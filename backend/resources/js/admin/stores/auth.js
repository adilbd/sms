import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import router from '@/router'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(localStorage.getItem('token') || null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value)
  const userRole = computed(() => user.value?.roles?.[0]?.name || null)

  const login = async (credentials) => {
    loading.value = true
    try {
      const response = await api.post('/login', credentials)
      token.value = response.data.token
      user.value = response.data.user

      localStorage.setItem('token', response.data.token)
      localStorage.setItem('user', JSON.stringify(response.data.user))

      return response.data
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
      user.value = response.data
      localStorage.setItem('user', JSON.stringify(response.data))
    } catch (error) {
      token.value = null
      user.value = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
    }
  }

  const hasPermission = (permission) => {
    return user.value?.permissions?.some(p => p.name === permission) || false
  }

  const hasRole = (role) => {
    return user.value?.roles?.some(r => r.name === role) || false
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

