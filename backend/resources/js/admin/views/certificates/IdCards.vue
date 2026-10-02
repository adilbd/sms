<template>
  <div class="id-cards-wrap">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ID cards</h1>
        <p class="text-sm text-gray-500">
          Front only, 85.6 × 54 mm, 10 cards per A4 page (2 × 5) with crop marks. Print at 100% scale (no "fit to page") and choose "Save as PDF" to make a PDF.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Section
          <select v-model="pickedSection" class="input mt-0.5 block" @change="openSection">
            <option value="">Choose a section...</option>
            <option v-for="section in sections" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
          </select>
        </label>
        <button type="button" class="btn btn-primary" :disabled="loading || cards.length === 0" @click="print">🖨️ Print {{ cards.length }} card{{ cards.length === 1 ? '' : 's' }}</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading cards...</p>
    <p v-else-if="!notice && !hasTarget" class="no-print text-center text-gray-500 py-8">Choose a section, or open this page from a student's page.</p>
    <p v-else-if="!notice && cards.length === 0" class="no-print text-center text-gray-500 py-8">No active students to print.</p>

    <section v-for="(page, pageIndex) in pages" :key="pageIndex" class="idc-page">
      <div v-for="card in page" :key="card.enrolment_id" class="idc-slot">
        <i class="idc-mark idc-tl"></i><i class="idc-mark idc-tr"></i><i class="idc-mark idc-bl"></i><i class="idc-mark idc-br"></i>
        <article class="idc-card">
          <header class="idc-head">
            <img v-if="school.logo_url" :src="school.logo_url" alt="" class="idc-logo" />
            <div>
              <strong class="idc-school">{{ school.name_en || school.name_bn }}</strong>
              <span v-if="school.name_en && school.name_bn" class="idc-school-bn">{{ school.name_bn }}</span>
            </div>
          </header>
          <div class="idc-body">
            <div class="idc-photo">
              <img v-if="card.photo_url" :src="card.photo_url" alt="" />
              <span v-else>{{ initials(card) }}</span>
            </div>
            <dl class="idc-fields">
              <div class="idc-name"><dt>Name</dt><dd>{{ card.name_en || card.name_bn }}<small v-if="card.name_en && card.name_bn">{{ card.name_bn }}</small></dd></div>
              <div><dt>ID</dt><dd>{{ card.student_id }}</dd></div>
              <div><dt>Class</dt><dd>{{ card.class_name }}, Sec {{ card.section }}<template v-if="card.group_en">, {{ card.group_en }}</template></dd></div>
              <div><dt>Roll</dt><dd>{{ card.roll_number ?? '-' }}<template v-if="card.shift_name_en"> · {{ card.shift_name_en }}</template></dd></div>
              <div v-if="card.blood_group"><dt>Blood</dt><dd>{{ card.blood_group }}</dd></div>
              <div v-if="card.guardian_mobile"><dt>Guardian</dt><dd>{{ card.guardian_mobile }}</dd></div>
            </dl>
          </div>
          <footer class="idc-foot">Valid until {{ validUntil(card.valid_until) }}</footer>
        </article>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { englishDate } from '@/utils/certificates'
import { GROUP_LABELS } from '@/constants/academic'

const route = useRoute()
const router = useRouter()

const PER_PAGE = 10

const cards = ref([])
const school = ref({})
const sections = ref([])
const pickedSection = ref(route.query.section_id ? Number(route.query.section_id) : '')
const loading = ref(false)
const notice = ref('')

const hasTarget = computed(() => Boolean(route.query.section_id || route.query.student_id))
const pages = computed(() => {
  const result = []
  for (let i = 0; i < cards.value.length; i += PER_PAGE) result.push(cards.value.slice(i, i + PER_PAGE))
  return result
})

const sectionLabel = (section) => {
  const parts = [`${section.class?.name ?? ''} – ${section.name}`.trim()]
  if (section.group) parts.push(GROUP_LABELS[section.group] || section.group)
  if (section.shift) parts.push(section.shift.name_en)
  return parts.join(' · ')
}

const initials = (card) =>
  (card.name_en || card.name_bn || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => Array.from(word)[0])
    .join('')
    .toUpperCase()

const validUntil = (ymd) => englishDate(ymd)

const openSection = () => {
  if (pickedSection.value) router.push({ path: '/id-cards', query: { section_id: pickedSection.value } })
}

const load = async () => {
  cards.value = []
  notice.value = ''
  if (!hasTarget.value) return

  loading.value = true
  try {
    const params = route.query.student_id ? { student_id: route.query.student_id } : { section_id: route.query.section_id }
    const { data } = await api.get('/id-cards', { params })
    cards.value = data.data.cards
    school.value = data.data.school
  } catch (error) {
    notice.value = error.response?.data?.errors?.student_id?.[0] || error.response?.data?.errors?.section_id?.[0] || error.response?.data?.message || 'Failed to load the ID cards'
  } finally {
    loading.value = false
  }
}

const print = () => window.print()

const PAGE_STYLE_ID = 'id-cards-page-style'

onMounted(async () => {
  document.body.classList.add('id-cards-page')
  const style = document.createElement('style')
  style.id = PAGE_STYLE_ID
  style.textContent = '@page { size: A4 portrait; margin: 0; }'
  document.head.appendChild(style)

  try {
    const { data } = await api.get('/sections', { params: { per_page: 100 } })
    sections.value = data.data
  } catch (error) {
    sections.value = []
  }

  await load()
})

onBeforeUnmount(() => {
  document.body.classList.remove('id-cards-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
/* One A4 page: 2 columns x 5 rows of CR80 cards (85.6 x 54 mm), with room for crop marks. */
.idc-page {
  display: grid;
  grid-template-columns: repeat(2, 85.6mm);
  grid-auto-rows: 54mm;
  gap: 4mm 8mm;
  justify-content: center;
  align-content: center;
  width: 210mm;
  height: 297mm;
  margin: 0 auto 8mm;
  background: #fff;
  box-shadow: 0 0 0 1px #e5e7eb;
  break-after: page;
}
.idc-slot { position: relative; width: 85.6mm; height: 54mm; }
.idc-mark { position: absolute; width: 2.5mm; height: 2.5mm; border: 0 solid #111827; }
.idc-tl { left: -2.5mm; top: -2.5mm; border-right-width: 0.2mm; border-bottom-width: 0.2mm; }
.idc-tr { right: -2.5mm; top: -2.5mm; border-left-width: 0.2mm; border-bottom-width: 0.2mm; }
.idc-bl { left: -2.5mm; bottom: -2.5mm; border-right-width: 0.2mm; border-top-width: 0.2mm; }
.idc-br { right: -2.5mm; bottom: -2.5mm; border-left-width: 0.2mm; border-top-width: 0.2mm; }
.idc-card {
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 0.2mm solid #9ca3af;
  border-radius: 2.5mm;
  background: #fff;
  color: #111827;
  font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif;
  font-size: 7pt;
  line-height: 1.25;
}
.idc-head { display: flex; align-items: center; gap: 2mm; padding: 1.6mm 3mm; background: #1e3a8a; color: #fff; }
.idc-logo { height: 8mm; width: 8mm; object-fit: contain; background: #fff; border-radius: 50%; }
.idc-school { display: block; font-size: 8pt; font-weight: 700; line-height: 1.15; }
.idc-school-bn { display: block; font-size: 6.5pt; opacity: 0.9; }
.idc-body { flex: 1; display: flex; gap: 3mm; padding: 2mm 3mm 0; min-height: 0; }
.idc-photo {
  flex: none;
  width: 22mm;
  height: 27mm;
  border: 0.2mm solid #9ca3af;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f3f4f6;
  color: #6b7280;
  font-size: 16pt;
  font-weight: 700;
  overflow: hidden;
}
.idc-photo img { width: 100%; height: 100%; object-fit: cover; }
.idc-fields { margin: 0; min-width: 0; flex: 1; }
.idc-fields > div { display: flex; gap: 1.5mm; }
.idc-fields dt { flex: none; width: 11mm; color: #6b7280; }
.idc-fields dd { margin: 0; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
.idc-fields small { display: block; font-weight: 400; }
.idc-name dd { font-size: 8.5pt; }
.idc-foot { padding: 1mm 3mm 1.6mm; text-align: right; font-size: 6.5pt; color: #374151; }

@media print {
  body.id-cards-page aside,
  body.id-cards-page header:not(.idc-head),
  body.id-cards-page .no-print { display: none !important; }
  body.id-cards-page .ml-64 { margin-left: 0 !important; }
  body.id-cards-page main { padding: 0 !important; }
  body.id-cards-page .min-h-screen { background: #fff !important; }

  .idc-page { margin: 0; box-shadow: none; }
  .idc-head, .idc-photo { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
