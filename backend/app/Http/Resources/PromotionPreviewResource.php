<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the array PromotionService::preview() returns. */
class PromotionPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'section' => new SectionResource($data['section']),
            'class' => new ClassResource($data['class']),
            'next_class' => $data['next_class'] ? new ClassResource($data['next_class']) : null,
            'suggested_target_section_id' => $data['suggested_target_section_id'],
            'needs_group_choice' => $data['needs_group_choice'],
            'rows' => array_map(function (array $row) {
                $enrolment = $row['enrolment'];
                $student = $enrolment->student;
                $item = [
                    'enrolment_id' => $enrolment->id,
                    'student' => [
                        'id' => $student->id,
                        'student_id' => $student->student_id,
                        'name_en' => $student->name_en,
                        'name_bn' => $student->name_bn,
                    ],
                    'roll_number' => $enrolment->roll_number,
                    'group' => $enrolment->group,
                    'optional_subject_id' => $enrolment->optional_subject_id,
                    'optional_subject' => $enrolment->optionalSubject
                        ? ['id' => $enrolment->optionalSubject->id, 'name' => $enrolment->optionalSubject->name, 'name_bn' => $enrolment->optionalSubject->name_bn]
                        : null,
                    'exam_result' => $row['exam_result'],
                    'suggested_action' => $row['suggested_action'],
                ];

                // Only present when the target year was asked for.
                if ($row['already_enrolled_in_target'] !== null) {
                    $item['already_enrolled_in_target'] = $row['already_enrolled_in_target'];
                }

                return $item;
            }, $data['rows']),
        ];
    }
}
