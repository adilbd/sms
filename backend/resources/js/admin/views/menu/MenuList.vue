<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Menu</h1>
      <router-link to="/menu/create" class="btn btn-primary">
        ➕ Add Menu Item
      </router-link>
    </div>

    <div class="card">
      <p class="text-sm text-gray-500 mb-4">
        This is the header navigation shown on the public site. Reordering, activating and
        deactivating items updates the site immediately.
      </p>

      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading menu…</p>
      </div>

      <div v-else-if="rows.length === 0" class="text-center py-8">
        <p class="text-gray-500">No menu items yet. Add one to build the header navigation.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Label</th>
              <th>Type</th>
              <th>Target</th>
              <th>Status</th>
              <th>Order</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
              <td>
                <span :style="{ paddingLeft: `${(row.depth - 1) * 1.5}rem` }" class="font-medium">
                  {{ row.depth > 1 ? '↳ ' : '' }}{{ row.label }}
                </span>
              </td>
              <td class="text-gray-500">{{ typeLabels[row.type] || row.type }}</td>
              <td class="text-gray-500 text-sm">{{ targetLabel(row) }}</td>
              <td>
                <span :class="['badge', row.is_active ? 'badge-success' : 'badge-warning']">
                  {{ row.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-1">
                  <button
                    class="text-gray-500 hover:text-primary-700 disabled:opacity-30"
                    :disabled="!canMoveUp(row)"
                    title="Move up"
                    @click="move(row, -1)"
                  >⬆️</button>
                  <button
                    class="text-gray-500 hover:text-primary-700 disabled:opacity-30"
                    :disabled="!canMoveDown(row)"
                    title="Move down"
                    @click="move(row, 1)"
                  >⬇️</button>
                </div>
              </td>
              <td>
                <div class="flex space-x-2">
                  <button
                    class="text-gray-600 hover:text-gray-900"
                    :title="row.is_active ? 'Deactivate' : 'Activate'"
                    @click="toggleActive(row)"
                  >
                    {{ row.is_active ? '🙈' : '👁️' }}
                  </button>
                  <router-link
                    :to="`/menu/${row.id}/edit`"
                    class="text-primary-600 hover:text-primary-800"
                    title="Edit"
                  >✏️</router-link>
                  <button
                    class="text-red-600 hover:text-red-800"
                    title="Delete"
                    @click="remove(row)"
                  >🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'

const items = ref([])
const loading = ref(false)

const typeLabels = {
  page: 'Page',
  route: 'Route',
  url: 'URL',
  heading: 'Heading (dropdown)',
}

const fetchItems = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/menu-items', { params: { location: 'header', per_page: 100 } })
    items.value = data.data
  } catch (error) {
    console.error('Failed to fetch menu items:', error)
  } finally {
    loading.value = false
  }
}

// Flattens the tree depth-first, in sort order, for the table. up/down buttons only
// compare a row against its own siblings (same parent_id).
const rows = computed(() => {
  const byParent = new Map()
  for (const item of items.value) {
    const key = item.parent_id ?? 'root'
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key).push(item)
  }
  for (const siblings of byParent.values()) {
    siblings.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  }

  const flat = []
  const walk = (parentKey, depth) => {
    for (const item of byParent.get(parentKey) || []) {
      flat.push({ ...item, depth })
      walk(item.id, depth + 1)
    }
  }
  walk('root', 1)

  return flat
})

const siblingsOf = (row) => rows.value.filter((r) => (r.parent_id ?? 'root') === (row.parent_id ?? 'root'))

const canMoveUp = (row) => siblingsOf(row).findIndex((r) => r.id === row.id) > 0
const canMoveDown = (row) => {
  const siblings = siblingsOf(row)
  return siblings.findIndex((r) => r.id === row.id) < siblings.length - 1
}

const move = async (row, direction) => {
  const siblings = siblingsOf(row)
  const index = siblings.findIndex((r) => r.id === row.id)
  const swapWith = siblings[index + direction]
  if (!swapWith) return

  try {
    await api.put('/menu-items/reorder', {
      items: [
        { id: row.id, parent_id: row.parent_id ?? null, sort_order: swapWith.sort_order },
        { id: swapWith.id, parent_id: swapWith.parent_id ?? null, sort_order: row.sort_order },
      ],
    })
    await fetchItems()
  } catch (error) {
    console.error('Failed to reorder menu items:', error)
    alert('Failed to reorder menu items')
  }
}

const toggleActive = async (row) => {
  try {
    await api.put(`/menu-items/${row.id}`, { is_active: !row.is_active })
    await fetchItems()
  } catch (error) {
    console.error('Failed to update menu item:', error)
    alert('Failed to update menu item')
  }
}

const remove = async (row) => {
  const hasChildren = items.value.some((item) => item.parent_id === row.id)
  const warning = hasChildren
    ? 'Delete this menu item? Its submenu items will be deleted too.'
    : 'Delete this menu item?'
  if (!confirm(warning)) return

  try {
    await api.delete(`/menu-items/${row.id}`)
    await fetchItems()
  } catch (error) {
    console.error('Failed to delete menu item:', error)
    alert('Failed to delete menu item')
  }
}

const targetLabel = (row) => {
  if (row.type === 'page') return row.page?.title || (row.page_id ? `Page #${row.page_id}` : '—')
  if (row.type === 'route') return row.route_name || '—'
  if (row.type === 'url') return row.url || '—'
  return '—'
}

onMounted(() => {
  fetchItems()
})
</script>
