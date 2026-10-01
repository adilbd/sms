import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  // Restored from the last login so permission checks work on the first navigation after a
  // page reload; checkAuth() refreshes it from /me.
  const storedUser = (() => {
    try {
      return JSON.parse(localStorage.getItem('user') || 'null')
    } catch {
      return null
    }
  })()
  const user = ref(storedUser)
  const token = ref(localStorage.getItem('token') || null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value)
  // roles and permissions arrive as plain name strings (UserResource)
  const userRole = computed(() => user.value?.roles?.[0] || null)

  const login = async (credentials) => {
    loading.value = true
    try {
      // credentials: { login, password }. login is an email, student ID or mobile number.
      const response = await api.post('/login', credentials)
      const { token: newToken, user: newUser } = response.data.data
      token.value = newToken
      user.value = newUser

      localStorage.setItem('token', newToken)
      localStorage.setItem('user', JSON.stringify(newUser))

      return response.data.data
    } catch (error) {
      throw error
    } finally {
      loading.value = false
    }
  }

  const logout = async () => {
    try {
      await api.post('/logout')
    } catch (error) {
      console.error('Logout error:', error)
    } finally {
      token.value = null
      user.value = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      router.push('/login')
    }
  }

  const checkAuth = async () => {
    if (!token.value) return

    try {
      const response = await api.get('/me')
      user.value = response.data.data
      localStorage.setItem('user', JSON.stringify(response.data.data))
    } catch (error) {
      token.value = null
      user.value = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
    }
  }

  const hasPermission = (permission) => {
    return user.value?.permissions?.includes(permission) || false
  }

  const hasRole = (role) => {
    return user.value?.roles?.includes(role) || false
  }

  return {
    user,
    token,
    loading,
    isAuthenticated,
    userRole,
    login,
    logout,
    checkAuth,
    hasPermission,
    hasRole,
  }
})

