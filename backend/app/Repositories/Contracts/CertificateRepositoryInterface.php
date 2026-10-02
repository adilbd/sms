<?php

namespace App\Repositories\Contracts;

use App\Models\Certificate;
use App\Models\Student;

interface CertificateRepositoryInterface extends RepositoryInterface
{
    /**
     * Takes a row lock on the student (`select ... for update`) and returns the fresh row.
     * Call inside a transaction.
     */
    public function lockStudent(int $studentId): Student;

    /**
     * Takes a row lock on the certificate and returns the fresh row. Call inside a transaction.
     */
    public function lockCertificate(Certificate $certificate): Certificate;

    /**
     * The next serial number for a (type, year) from its locked counter row. The row is
     * selected `for update` first and inserted only when missing, so two issues never
     * deadlock on an `INSERT IGNORE` shared lock. Call inside a transaction.
     */
    public function nextSerialNumber(string $type, int $year): int;

    /**
     * Whether the student has a transfer certificate that isn't cancelled.
     */
    public function hasActiveTransfer(int $studentId): bool;

    /**
     * Loads what the single-certificate responses need: student, issuer, canceller and the
     * enrolment's section id.
     */
    public function loadDetail(Certificate $certificate): Certificate;
}
