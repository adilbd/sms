// Who may open a route or see a sidebar entry. `access` is `{ permission?, role? }`:
// `permission` (a name or a list, any of which is enough) and `role` (a name or a list,
// any of which is enough) must both be satisfied when both are given; nothing given means
// any signed-in user. This only hides things: the API enforces the same rules.
export const canAccess = (auth, access = {}) => {
  const list = (value) => (value === undefined || value === null ? [] : [].concat(value))
  const roles = list(access.role)
  const permissions = list(access.permission)

  if (roles.length && !roles.some((role) => auth.hasRole(role))) return false
  if (permissions.length && !permissions.some((permission) => auth.hasPermission(permission))) return false

  return true
}

// A teacher who is not also an admin: limited to their own work, so the sidebar shows
// the short teacher menu.
export const isTeacherOnly = (auth) => auth.hasRole('teacher') && !auth.hasRole('admin')
