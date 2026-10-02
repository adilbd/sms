<template>
  <p v-if="empty" class="text-gray-500 py-6">{{ bn ? 'এখনো কোনো রুটিন তৈরি হয়নি।' : 'No routine has been set up yet.' }}</p>

  <div v-else class="overflow-x-auto">
    <table class="routine-table">
      <thead>
        <tr>
          <th class="routine-left">{{ bn ? (isTeacher ? 'সময়' : 'পিরিয়ড') : (isTeacher ? 'Time' : 'Period') }}</th>
          <th v-for="day in routine.days" :key="day">{{ dayLabel(day, bn) }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.key">
          <th scope="row" class="routine-left">
            <span v-if="row.period">{{ pick(row.period.name_bn, row.period.name_en, bn) }}</span>
            <span class="routine-time">{{ clock(row.start, bn) }} – {{ clock(row.end, bn) }}</span>
          </th>
          <td v-if="row.period?.is_break" :colspan="routine.days.length" class="routine-break">
            {{ pick(row.period.name_bn, row.period.name_en, bn) }}
          </td>
          <template v-else>
            <td v-for="day in routine.days" :key="day">
              <div v-for="slot in cell(row, day)" :key="slot.id" class="routine-cell">
                <strong>{{ pick(slot.subject?.name_bn, slot.subject?.name, bn) }}</strong>
                <span v-if="isTeacher && slot.section">{{ sectionLabel(slot.section) }}</span>
                <span v-if="!isTeacher && slot.staff">{{ pick(slot.staff.name_bn, slot.staff.name_en, bn) }}</span>
                <span v-if="slot.room" class="routine-room">{{ bn ? 'কক্ষ' : 'Room' }}: {{ slot.room }}</span>
              </div>
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
// A read-only routine table: a section's (rows are its shift's periods) or one teacher's
// week (rows are the distinct time ranges they teach in). `routine` is the API's
// `data` of GET /routines/sections/{id}, /routines/teachers/{id} or /my/routine.
import { computed } from 'vue'
import { clock, dayLabel, pick, sectionLabel } from '@/utils/routine'

const props = defineProps({
  routine: { type: Object, required: true },
  bn: { type: Boolean, default: false },
})

const isTeacher = computed(() => props.routine.kind === 'teacher')
const empty = computed(() => (isTeacher.value ? props.routine.slots.length === 0 : !props.routine.section || props.routine.periods.length === 0 || props.routine.slots.length === 0))

const rows = computed(() => {
  if (!isTeacher.value) {
    return props.routine.periods.map((period) => ({ key: period.id, period, start: period.start_time, end: period.end_time }))
  }

  const seen = new Map()
  for (const slot of props.routine.slots) {
    const key = `${slot.period.start_time}-${slot.period.end_time}`
    if (!seen.has(key)) seen.set(key, { key, period: null, start: slot.period.start_time, end: slot.period.end_time })
  }

  return [...seen.values()].sort((a, b) => a.start.localeCompare(b.start))
})

const cell = (row, day) =>
  props.routine.slots.filter((slot) => slot.day === day && (isTeacher.value
    ? slot.period.start_time === row.start && slot.period.end_time === row.end
    : slot.period_id === row.period.id))
</script>

<style>
.routine-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.routine-table th, .routine-table td { border: 1px solid #9ca3af; padding: 6px 8px; text-align: center; vertical-align: top; }
.routine-table thead th { background: #f3f4f6; font-weight: 600; }
.routine-table .routine-left { text-align: left; white-space: nowrap; background: #f9fafb; }
.routine-time { display: block; font-size: 11px; font-weight: 400; color: #6b7280; }
.routine-break { background: #f3f4f6; color: #6b7280; vertical-align: middle !important; }
.routine-cell { display: flex; flex-direction: column; gap: 1px; }
.routine-cell + .routine-cell { margin-top: 4px; padding-top: 4px; border-top: 1px dashed #d1d5db; }
.routine-cell span { font-size: 11px; color: #4b5563; }
.routine-cell .routine-room { color: #6b7280; }
</style>
