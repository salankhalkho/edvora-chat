<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class DepartmentController
{
    public function index(Request $request): void
    {
        Response::success([
            'departments' => [],
            'available_staff' => [],
            'available_knowledge_sources' => [],
            'available_campuses' => []
        ]);
    }

    public function presets(Request $request): void
    {
        Response::success([]);
    }

    public function importPresets(Request $request): void
    {
        Response::success([], 'Departments feature has been deprecated. Programs are managed under Academic Programs.');
    }

    public function create(Request $request): void
    {
        Response::success([], 'Departments feature has been deprecated. Programs are managed under Academic Programs.');
    }

    public function update(Request $request, array $params = []): void
    {
        Response::success([], 'Department updated.');
    }

    public function delete(Request $request, array $params = []): void
    {
        Response::success([], 'Department deleted.');
    }

    public function syncStaff(Request $request, array $params = []): void
    {
        Response::success([], 'Staff synced.');
    }

    public function syncKnowledge(Request $request, array $params = []): void
    {
        Response::success([], 'Knowledge synced.');
    }

    public function manageFaqs(Request $request, array $params = []): void
    {
        Response::success([], 'FAQs updated.');
    }

    public function manageCourses(Request $request, array $params = []): void
    {
        Response::success([], 'Courses updated.');
    }

    public function publicList(Request $request, array $params = []): void
    {
        Response::success(['departments' => []]);
    }

    public function publicDashboard(Request $request, array $params = []): void
    {
        Response::success(['departments' => []]);
    }
}
