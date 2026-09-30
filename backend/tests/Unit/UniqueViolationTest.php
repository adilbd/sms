<?php

namespace Tests\Unit;

use App\Support\UniqueViolation;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUniqueViolation;

class UniqueViolationTest extends TestCase
{
    public function test_it_identifies_the_index_on_both_drivers_despite_the_sql_naming_every_column(): void
    {
        $columns = ['name', 'email', 'username', 'birth_registration_number', 'roll_number'];

        foreach (FakeUniqueViolation::drivers() as $driver) {
            $e = FakeUniqueViolation::make($driver, 'users', ['username'], $columns);

            $this->assertTrue(UniqueViolation::is($e, 'users', ['username']), $driver);
            $this->assertFalse(UniqueViolation::is($e, 'users', ['email']), $driver);
            $this->assertFalse(UniqueViolation::is($e, 'students', ['username']), $driver);
        }
    }

    public function test_it_matches_composite_and_custom_named_indexes(): void
    {
        $roll = ['section_id', 'academic_year_id', 'roll_number'];

        foreach (FakeUniqueViolation::drivers() as $driver) {
            $e = FakeUniqueViolation::make($driver, 'student_enrolments', $roll, $roll, 'student_enrolments_section_year_roll_unique');

            $this->assertTrue(UniqueViolation::is($e, 'student_enrolments', $roll, 'student_enrolments_section_year_roll_unique'), $driver);
            $this->assertFalse(UniqueViolation::is($e, 'student_enrolments', ['student_id', 'academic_year_id']), $driver);
        }
    }

    public function test_it_accepts_the_mysql_key_name_without_the_table_prefix(): void
    {
        $e = new \Illuminate\Database\UniqueConstraintViolationException(
            'mysql', 'insert into `users` (`email`) values (?)', ['x'],
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c' for key 'users_email_unique'"),
        );

        $this->assertTrue(UniqueViolation::is($e, 'users', ['email']));
        $this->assertFalse(UniqueViolation::is($e, 'users', ['username']));
    }
}
