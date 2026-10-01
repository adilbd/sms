<?php

namespace App\Providers;

use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\ExamMarkRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use App\Repositories\Contracts\GalleryRepositoryInterface;
use App\Repositories\Contracts\MediaRepositoryInterface;
use App\Repositories\Contracts\MenuItemRepositoryInterface;
use App\Repositories\Contracts\PageRepositoryInterface;
use App\Repositories\Contracts\PostRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\AcademicYearRepository;
use App\Repositories\Eloquent\ClassRepository;
use App\Repositories\Eloquent\ClassSubjectRepository;
use App\Repositories\Eloquent\ClassTeacherRepository;
use App\Repositories\Eloquent\ExamMarkRepository;
use App\Repositories\Eloquent\ExamRepository;
use App\Repositories\Eloquent\ExamResultRepository;
use App\Repositories\Eloquent\GalleryRepository;
use App\Repositories\Eloquent\MediaRepository;
use App\Repositories\Eloquent\MenuItemRepository;
use App\Repositories\Eloquent\PageRepository;
use App\Repositories\Eloquent\PostRepository;
use App\Repositories\Eloquent\SectionRepository;
use App\Repositories\Eloquent\SettingRepository;
use App\Repositories\Eloquent\ShiftRepository;
use App\Repositories\Eloquent\StaffRepository;
use App\Repositories\Eloquent\StudentEnrolmentRepository;
use App\Repositories\Eloquent\StudentRepository;
use App\Repositories\Eloquent\SubjectAssignmentRepository;
use App\Repositories\Eloquent\SubjectRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds every repository interface to its implementation.
 * Add one line per new repository.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        SubjectRepositoryInterface::class => SubjectRepository::class,
        PostRepositoryInterface::class => PostRepository::class,
        PageRepositoryInterface::class => PageRepository::class,
        MenuItemRepositoryInterface::class => MenuItemRepository::class,
        SettingRepositoryInterface::class => SettingRepository::class,
        MediaRepositoryInterface::class => MediaRepository::class,
        GalleryRepositoryInterface::class => GalleryRepository::class,
        StaffRepositoryInterface::class => StaffRepository::class,
        ShiftRepositoryInterface::class => ShiftRepository::class,
        ClassRepositoryInterface::class => ClassRepository::class,
        SectionRepositoryInterface::class => SectionRepository::class,
        AcademicYearRepositoryInterface::class => AcademicYearRepository::class,
        ClassTeacherRepositoryInterface::class => ClassTeacherRepository::class,
        ClassSubjectRepositoryInterface::class => ClassSubjectRepository::class,
        SubjectAssignmentRepositoryInterface::class => SubjectAssignmentRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
        StudentRepositoryInterface::class => StudentRepository::class,
        StudentEnrolmentRepositoryInterface::class => StudentEnrolmentRepository::class,
        ExamRepositoryInterface::class => ExamRepository::class,
        ExamMarkRepositoryInterface::class => ExamMarkRepository::class,
        ExamResultRepositoryInterface::class => ExamResultRepository::class,
    ];
}
