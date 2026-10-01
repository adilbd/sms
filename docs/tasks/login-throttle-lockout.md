# Stop the login throttle from locking out real users

Status: done
Branch: fix/login-throttle-lockout

## Problem
The Students module added a login throttle, and the round-3 review left three nits on it, all in how login attempts are limited:

1. **Anyone can lock an account indefinitely.** `AuthService` locks an account after 5 failed passwords in a minute, and the lock also rejects the correct password. An attacker who knows a username (such as `admin@sms.com`) can send 5 bad passwords every minute and keep the owner out forever.
2. **The lockout response has no `Retry-After` header.** When the per-account lock triggers, `ThrottleRequestsException` is thrown without one, so clients can't tell when to try again.
3. **A shared school network can be blocked.** The per-IP route limit allows 20 attempts a minute and counts successful logins too. A school lab or office behind one public IP can hit it during the morning rush.

The intended outcome: brute-forcing a password stays expensive, but an attacker can't keep a known account locked out, and a whole school signing in from one IP isn't throttled.

## Scope
**In:**
1. **Per-account lock** (`AuthService`):
   - Raise `MAX_FAILURES` from 5 to 10 failures a minute.
   - **Trusted IP:** after a successful login, remember the user and IP pair as trusted for 30 days (cache key such as `login-trusted:{userId}:{sha1(ip)}`). A login from a trusted IP skips the per-account lock, so its owner can sign in while an attacker elsewhere is hammering the account. Failures from a trusted IP still count toward the per-IP limit.
   - **Retry-After:** the 429 from the per-account lock includes a `Retry-After` header, set from `RateLimiter::availableIn($key)`.
2. **Per-IP limit:**
   - Move it out of the route limiter and into `AuthService`, so it counts only failed attempts: 30 failures a minute per IP, keyed `login-ip-fail:{ip}`. Once it triggers, every login from that IP gets a 429 with `Retry-After`, including a correct password.
   - Successful logins never count toward it.
   - The controller passes the IP to the service as a plain string. The service still never receives the `Request`.
3. **Route limiter:** keep the existing `throttle:login` limit of 5 attempts a minute per identifier and IP. One person is retrying one login, so successes counting there is fine. Its 429 already includes `Retry-After`.
4. **Docs:** update the throttle description in the Students note in `CLAUDE.md`.

**Out:**
- CAPTCHA and SMS or OTP checks.
- Admin-visible lockout alerts or audit logs.
- Changing the token or session model.

## Guidelines that apply
- `docs/architecture-guidelines.md`:
  - The controller stays thin, and the rules live in `AuthService`.
  - `RateLimiter` and `Cache` are framework facades, not Eloquent queries, so they're fine in the service. Any user lookups stay in `UserRepository`.
- `docs/api-response-guidelines.md`: a 429 uses the standard error shape `{message}`, and every 429 carries `Retry-After`.

## Residual risks (accepted)
- **First-time users can still be locked out.** A user with no trusted IP yet, on a first login or a new device, can be locked out for a minute at a time by 10 failures from other IPs.
- **A shared network can be blocked.** Someone on a school's NAT can block that IP for a minute with 30 bad attempts. Everyone behind one IP also shares its trust.
- **It assumes there's no proxy.** `trustProxies` isn't configured. Behind Cloudflare or a load balancer, `$request->ip()` would be the proxy's address. Configure it when hosting is chosen.
- **Trust isn't cleared on a password change or deactivation.** Trust only skips the lock and never grants access. This is a follow-up nit.

## Acceptance criteria
- [x] An attacker sending wrong passwords to a known account from IPs it hasn't logged in from can't stop the owner signing in from a trusted IP.
- [x] Brute force against one account across many untrusted IPs is still capped at 10 failures a minute for that account.
- [x] Successful logins never count toward the per-IP limit. Failures from one IP are capped at 30 a minute.
- [x] Every 429 from login (per-account, per-IP and route limiter) has the `{message}` shape and a `Retry-After` header.
- [x] The existing `AuthApiTest` and smoke checks still pass.
- [x] `CLAUDE.md` is updated.

## Test cases
- [x] **Happy path.**
  - The correct password logs in and marks the IP as trusted for that user.
  - From a trusted IP, the correct password succeeds even while the account is locked from other IPs.
- [x] **Per-account lock.**
  - 10 wrong passwords from different untrusted IPs, then an 11th attempt with the correct password from another untrusted IP, returns 429 with `Retry-After` > 0.
  - Once the decay has passed (or the limiter is cleared), the correct password works again.
- [x] **Trusted IP.**
  - A trusted IP's own failures still count toward its per-IP limit.
  - Trust is per user: being trusted for user A doesn't let that IP skip user B's lock.
  - Trust expires after 30 days. Use the cache TTL, or time travel in the test.
- [x] **Per-IP failures.**
  - 30 failures from one IP across different identifiers, then a 31st attempt, returns 429.
  - 40 successful logins from one IP by different users all return 200. Keep the route limit's per-identifier counts under 5 each.
- [x] **Route limiter.** The 6th attempt for the same identifier and IP returns 429 with `Retry-After`.
- [x] **Unit tests.** `AuthServiceTest` covers the trusted-IP bypass, the failure-only per-IP count and the `Retry-After` header on a mocked repository.
