<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\SchoolCycle;
use App\Models\SchoolGroup;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\Assessment;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Grade;
use App\Models\GradingPeriod;

class SchoolDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $campus = Campus::updateOrCreate(
                ['code' => 'PLANTEL-001'],
                [
                    'name' => 'Plantel principal',
                    'official_key' => 'ESC-DEMO-001',
                    'email' => 'contacto@example.test',
                    'phone' => '5550000000',
                    'street' => 'Avenida de Ejemplo',
                    'external_number' => '100',
                    'neighborhood' => 'Colonia de Prueba',
                    'postal_code' => '06000',
                    'locality' => 'Ciudad de México',
                    'municipality' => 'Cuauhtémoc',
                    'state' => 'Ciudad de México',
                    'country_code' => 'MX',
                    'is_active' => true,
                ]
            );

            SchoolCycle::query()
                ->where('campus_id', $campus->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $cycle = SchoolCycle::updateOrCreate(
                [
                    'campus_id' => $campus->id,
                    'name' => '2026-2027',
                ],
                [
                    'starts_on' => '2026-08-24',
                    'ends_on' => '2027-07-16',
                    'status' => 'active',
                    'is_current' => true,
                ]
            );

            $subjectDefinitions = [
                [
                    'code' => 'MAT-01',
                    'name' => 'Matemáticas',
                    'weekly_hours' => 5,
                ],
                [
                    'code' => 'ESP-01',
                    'name' => 'Español',
                    'weekly_hours' => 5,
                ],
                [
                    'code' => 'CIE-01',
                    'name' => 'Ciencias',
                    'weekly_hours' => 4,
                ],
                [
                    'code' => 'GEO-01',
                    'name' => 'Geografía',
                    'weekly_hours' => 3,
                ],
            ];

            $subjects = collect($subjectDefinitions)->mapWithKeys(
                function (array $definition) use ($campus): array {
                    $subject = Subject::updateOrCreate(
                        [
                            'campus_id' => $campus->id,
                            'code' => $definition['code'],
                        ],
                        [
                            'name' => $definition['name'],
                            'weekly_hours' => $definition['weekly_hours'],
                            'is_active' => true,
                        ]
                    );

                    return [$definition['code'] => $subject];
                }
            );

            $group = SchoolGroup::updateOrCreate(
                [
                    'school_cycle_id' => $cycle->id,
                    'grade_level' => '1',
                    'section' => 'A',
                    'shift' => 'morning',
                ],
                [
                    'capacity' => 40,
                    'classroom' => 'A-101',
                    'is_active' => true,
                ]
            );

            $teacherDefinitions = [
                [
                    'curp' => 'XAXX800101HDFXXX01',
                    'first_name' => 'Carlos',
                    'middle_name' => null,
                    'paternal_surname' => 'Martínez',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '1980-01-01',
                    'sex' => 'male',
                    'email' => 'carlos.martinez@example.test',
                    'employee_number' => 'PROF-001',
                ],
                [
                    'curp' => 'XAXX820202MDFXXX02',
                    'first_name' => 'Elena',
                    'middle_name' => null,
                    'paternal_surname' => 'Hernández',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '1982-02-02',
                    'sex' => 'female',
                    'email' => 'elena.hernandez@example.test',
                    'employee_number' => 'PROF-002',
                ],
            ];

            $teachers = collect($teacherDefinitions)->map(
                function (array $definition) use ($campus): Teacher {
                    $person = Person::updateOrCreate(
                        ['curp' => $definition['curp']],
                        [
                            'first_name' => $definition['first_name'],
                            'middle_name' => $definition['middle_name'],
                            'paternal_surname' => $definition['paternal_surname'],
                            'maternal_surname' => $definition['maternal_surname'],
                            'birth_date' => $definition['birth_date'],
                            'sex' => $definition['sex'],
                            'email' => $definition['email'],
                            'additional_data' => [
                                'is_demo' => true,
                            ],
                        ]
                    );

                    return Teacher::updateOrCreate(
                        [
                            'campus_id' => $campus->id,
                            'employee_number' => $definition['employee_number'],
                        ],
                        [
                            'person_id' => $person->id,
                            'hired_on' => '2026-08-01',
                            'status' => 'active',
                        ]
                    );
                }
            );

            $studentDefinitions = [
                [
                    'curp' => 'XAXX120101HDFXXX01',
                    'first_name' => 'Diego',
                    'paternal_surname' => 'López',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '2012-01-01',
                    'sex' => 'male',
                    'enrollment_number' => 'ALU-001',
                ],
                [
                    'curp' => 'XAXX120202MDFXXX02',
                    'first_name' => 'Sofía',
                    'paternal_surname' => 'García',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '2012-02-02',
                    'sex' => 'female',
                    'enrollment_number' => 'ALU-002',
                ],
                [
                    'curp' => 'XAXX120303HDFXXX03',
                    'first_name' => 'Mateo',
                    'paternal_surname' => 'Sánchez',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '2012-03-03',
                    'sex' => 'male',
                    'enrollment_number' => 'ALU-003',
                ],
                [
                    'curp' => 'XAXX120404MDFXXX04',
                    'first_name' => 'Valentina',
                    'paternal_surname' => 'Ramírez',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '2012-04-04',
                    'sex' => 'female',
                    'enrollment_number' => 'ALU-004',
                ],
                [
                    'curp' => 'XAXX120505HDFXXX05',
                    'first_name' => 'Emiliano',
                    'paternal_surname' => 'Torres',
                    'maternal_surname' => 'Demo',
                    'birth_date' => '2012-05-05',
                    'sex' => 'male',
                    'enrollment_number' => 'ALU-005',
                ],
            ];

            foreach ($studentDefinitions as $definition) {
                $person = Person::updateOrCreate(
                    ['curp' => $definition['curp']],
                    [
                        'first_name' => $definition['first_name'],
                        'middle_name' => null,
                        'paternal_surname' => $definition['paternal_surname'],
                        'maternal_surname' => $definition['maternal_surname'],
                        'birth_date' => $definition['birth_date'],
                        'sex' => $definition['sex'],
                        'additional_data' => [
                            'is_demo' => true,
                        ],
                    ]
                );

                $student = Student::updateOrCreate(
                    [
                        'campus_id' => $campus->id,
                        'enrollment_number' => $definition['enrollment_number'],
                    ],
                    [
                        'person_id' => $person->id,
                        'enrolled_on' => '2026-08-24',
                        'status' => 'active',
                    ]
                );

                Enrollment::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'school_cycle_id' => $cycle->id,
                    ],
                    [
                        'school_group_id' => $group->id,
                        'enrolled_on' => '2026-08-24',
                        'status' => 'active',
                    ]
                );
            }

            $assignments = [
                ['MAT-01', 0],
                ['CIE-01', 0],
                ['ESP-01', 1],
                ['GEO-01', 1],
            ];

            foreach ($assignments as [$subjectCode, $teacherIndex]) {
                TeachingAssignment::updateOrCreate(
                    [
                        'school_group_id' => $group->id,
                        'subject_id' => $subjects[$subjectCode]->id,
                        'teacher_id' => $teachers[$teacherIndex]->id,
                    ],
                    [
                        'starts_on' => '2026-08-24',
                        'ends_on' => '2027-07-16',
                        'status' => 'active',
                    ]
                );
            }

            $gradingPeriod = GradingPeriod::updateOrCreate(
    [
        'school_cycle_id' => $cycle->id,
        'sequence' => 1,
    ],
    [
        'name' => 'Primer periodo',
        'starts_on' => '2026-08-24',
        'ends_on' => '2026-11-27',
        'status' => 'active',
    ]
);

$teachingAssignments = TeachingAssignment::query()
    ->with('subject')
    ->where('school_group_id', $group->id)
    ->get()
    ->keyBy(fn (TeachingAssignment $assignment): string => (
        $assignment->subject->code
    ));

$assessmentDefinitions = [
    [
        'subject_code' => 'MAT-01',
        'name' => 'Examen del primer periodo',
        'type' => 'exam',
        'maximum_score' => 100,
        'weight' => 60,
        'due_at' => '2026-11-20 15:00:00+00',
    ],
    [
        'subject_code' => 'MAT-01',
        'name' => 'Proyecto matemático',
        'type' => 'project',
        'maximum_score' => 100,
        'weight' => 40,
        'due_at' => '2026-11-25 15:00:00+00',
    ],
    [
        'subject_code' => 'ESP-01',
        'name' => 'Comprensión lectora',
        'type' => 'exam',
        'maximum_score' => 100,
        'weight' => 60,
        'due_at' => '2026-11-19 15:00:00+00',
    ],
    [
        'subject_code' => 'ESP-01',
        'name' => 'Ensayo',
        'type' => 'project',
        'maximum_score' => 100,
        'weight' => 40,
        'due_at' => '2026-11-24 15:00:00+00',
    ],
    [
        'subject_code' => 'CIE-01',
        'name' => 'Examen de ciencias',
        'type' => 'exam',
        'maximum_score' => 100,
        'weight' => 60,
        'due_at' => '2026-11-18 15:00:00+00',
    ],
    [
        'subject_code' => 'CIE-01',
        'name' => 'Práctica de laboratorio',
        'type' => 'practice',
        'maximum_score' => 100,
        'weight' => 40,
        'due_at' => '2026-11-23 15:00:00+00',
    ],
    [
        'subject_code' => 'GEO-01',
        'name' => 'Examen de geografía',
        'type' => 'exam',
        'maximum_score' => 100,
        'weight' => 60,
        'due_at' => '2026-11-17 15:00:00+00',
    ],
    [
        'subject_code' => 'GEO-01',
        'name' => 'Mapa temático',
        'type' => 'project',
        'maximum_score' => 100,
        'weight' => 40,
        'due_at' => '2026-11-26 15:00:00+00',
    ],
];

$createdAssessments = collect();

foreach ($assessmentDefinitions as $definition) {
    $assignment = $teachingAssignments[
        $definition['subject_code']
    ];

    $assessment = Assessment::updateOrCreate(
        [
            'teaching_assignment_id' => $assignment->id,
            'grading_period_id' => $gradingPeriod->id,
            'name' => $definition['name'],
        ],
        [
            'type' => $definition['type'],
            'maximum_score' => $definition['maximum_score'],
            'weight' => $definition['weight'],
            'due_at' => $definition['due_at'],
            'status' => 'published',
        ]
    );

    $createdAssessments->push($assessment);
}

$enrollments = Enrollment::query()
    ->where('school_group_id', $group->id)
    ->where('status', 'active')
    ->orderBy('id')
    ->get();

$gradeScores = [95, 88, 82, 76, 91];

foreach ($createdAssessments as $assessmentIndex => $assessment) {
    $assignment = $assessment->teachingAssignment;

    foreach ($enrollments as $studentIndex => $enrollment) {
        /*
         * Se genera una pequeña variación por evaluación,
         * manteniendo la calificación entre 0 y 100.
         */
        $score = max(
            0,
            min(
                100,
                $gradeScores[$studentIndex]
                    - ($assessmentIndex % 3)
            )
        );

        Grade::updateOrCreate(
            [
                'assessment_id' => $assessment->id,
                'enrollment_id' => $enrollment->id,
            ],
            [
                'graded_by_teacher_id' => $assignment->teacher_id,
                'score' => $score,
                'feedback' => 'Calificación ficticia de demostración.',
                'graded_at' => '2026-11-27 18:00:00+00',
                'status' => 'graded',
            ]
        );
    }
}

$attendanceSession = AttendanceSession::updateOrCreate(
    [
        'school_group_id' => $group->id,
        'teaching_assignment_id' => null,
        'held_on' => '2026-09-01',
        'starts_at' => null,
    ],
    [
        'recorded_by_teacher_id' => $teachers->first()->id,
        'ends_at' => null,
        'type' => 'daily',
        'status' => 'closed',
        'notes' => 'Asistencia ficticia de demostración.',
    ]
);

$attendanceDefinitions = [
    ['status' => 'present', 'minutes_late' => 0],
    ['status' => 'present', 'minutes_late' => 0],
    ['status' => 'late', 'minutes_late' => 10],
    ['status' => 'absent', 'minutes_late' => 0],
    ['status' => 'excused', 'minutes_late' => 0],
];

foreach ($enrollments as $index => $enrollment) {
    $attendance = $attendanceDefinitions[$index];

    AttendanceRecord::updateOrCreate(
        [
            'attendance_session_id' => $attendanceSession->id,
            'enrollment_id' => $enrollment->id,
        ],
        [
            'status' => $attendance['status'],
            'minutes_late' => $attendance['minutes_late'],
            'notes' => 'Registro ficticio de demostración.',
            'recorded_at' => '2026-09-01 14:00:00+00',
        ]
    );
}
        });

        $this->command?->info(
            'Datos escolares ficticios creados correctamente.'
        );
    }
}