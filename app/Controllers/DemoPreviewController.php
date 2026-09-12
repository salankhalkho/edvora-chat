<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DemoPreviewService;
use Exception;
use Throwable;

class DemoPreviewController
{
    /**
     * POST /v1/preview/analyze
     * Public endpoint: Analyze website URL via real cURL crawl
     */
    public function analyze(Request $request): void
    {
        $url = trim((string)$request->get('url'));
        if (empty($url)) {
            Response::error("Please provide a university website URL.", 422);
            return;
        }

        try {
            $result = DemoPreviewService::analyzeWebsite($url);
            if (!$result['success']) {
                Response::error($result['error'] ?? "Could not connect to website.", 400, [
                    'session_token' => $result['session_token'] ?? null,
                    'domain'        => $result['domain'] ?? null,
                    'scrape_status' => 'failed'
                ]);
                return;
            }

            Response::success($result, "Website analyzed successfully.");
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/preview/chat
     * Public endpoint: Chat with AI admissions counselor grounded in university data
     */
    public function chat(Request $request): void
    {
        $sessionToken = trim((string)$request->get('session_token'));
        $message = trim((string)$request->get('message'));
        $history = (array)$request->get('history', []);

        if (empty($sessionToken)) {
            Response::error("Session token is required.", 422);
            return;
        }
        if (empty($message)) {
            Response::error("Message cannot be empty.", 422);
            return;
        }

        try {
            $result = DemoPreviewService::generateChatResponse($sessionToken, $message, $history);
            Response::success($result);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/preview/capture-email
     * Public endpoint: Capture work email for demo access (Lead #1)
     */
    public function captureEmail(Request $request): void
    {
        $sessionToken = trim((string)$request->get('session_token'));
        $email = trim((string)$request->get('email'));

        if (empty($sessionToken)) {
            Response::error("Session token is required.", 422);
            return;
        }
        if (empty($email)) {
            Response::error("Work email is required.", 422);
            return;
        }

        try {
            $result = DemoPreviewService::captureEmail($sessionToken, $email);
            Response::success($result, "Email recorded successfully.");
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /v1/preview/counselor-request
     * Public endpoint: Capture counselor callback inquiry inside chat (Lead #2)
     */
    public function counselorRequest(Request $request): void
    {
        $sessionToken = trim((string)$request->get('session_token'));
        $name = trim((string)$request->get('name'));
        $phone = trim((string)$request->get('phone'));
        $requestType = trim((string)$request->get('request_type', 'counselor'));

        if (empty($sessionToken)) {
            Response::error("Session token is required.", 422);
            return;
        }

        try {
            $result = DemoPreviewService::counselorRequest($sessionToken, $name, $phone, $requestType);
            Response::success($result, "Request received! An admissions counselor will reach out.");
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 422);
        }
    }
}
