<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div class="bg-white rounded-2xl shadow-2xl p-8">
        <div class="text-center">
          <h2 class="text-3xl font-extrabold text-gray-900 mb-2">
            School Management System
          </h2>
          <p class="text-sm text-gray-600">Sign in to your account</p>
        </div>

        <form class="mt-8 space-y-6" @submit.prevent="handleLogin">
          <div v-if="fromPortal" class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-sm" data-test="portal-note">
            This login is for staff. Students and guardians sign in at the
            <a href="/portal/login" class="font-medium underline">portal</a>.
          </div>

          <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ error }}
          </div>

          <div class="space-y-4">
            <div>
              <label for="login" class="block text-sm font-medium text-gray-700 mb-1">
                Email, student ID or mobile
              </label>
              <input
                id="login"
                v-model="form.login"
                type="text"
                required
                autocomplete="username"
                class="input"
                placeholder="admin@sms.com"
              />
            </div>

            <div>
              <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                Password
              </label>
              <input
                id="password"
                v-model="form.password"
                type="password"
                required
                class="input"
                placeholder="••••••••"
              />
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input
                id="remember-me"
                v-model="form.remember"
                type="checkbox"
                class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
              />
              <label for="remember-me" class="ml-2 block text-sm text-gray-900">
                Remember me
              </label>
            </div>

            <div class="text-sm">
              <a href="#" class="font-medium text-primary-600 hover:text-primary-500">
                Forgot password?
              </a>
            </div>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full btn btn-primary py-3 text-lg"
          >
            <span v-if="!loading">Sign in</span>
            <span v-else>Signing in...</span>
          </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-600">
          <p>Default credentials: <strong>admin@sms.com</strong> / <strong>password</strong></p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()

// The portal sends staff here with ?from=portal after they sign in on the wrong login.
const fromPortal = route.query.from === 'portal'
const authStore = useAuthStore()

const form = reactive({
  login: 'admin@sms.com',
  password: 'password',
  remember: false,
})

const error = ref(null)
const loading = ref(false)

const handleLogin = async () => {
  error.value = null
  loading.value = true

  try {
    await authStore.login({
      login: form.login,
      password: form.password,
    })
    router.push('/')
  } catch (err) {
    error.value = err.response?.data?.errors?.login?.[0] || err.response?.data?.message || 'Invalid credentials'
  } finally {
    loading.value = false
  }
}
</script>

