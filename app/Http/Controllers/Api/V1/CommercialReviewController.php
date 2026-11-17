<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommercialReviewService;
use App\Services\CaseOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CommercialReviewController extends Controller
{
    public function __construct(
        private readonly CommercialReviewService $reviews,
        private readonly CaseOperations $cases
    ) {}

    private function json(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'term_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', 'pending', 'reviewed', 'changes_requested', 'withdrawn'])],
            'scope' => ['sometimes', Rule::in(['all', 'requested', 'assigned'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json(['data' => $this->reviews->index($filters, (int) $request->user('api')->id)]);
    }

    public function show(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->show($review)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->cases->restrict($request, [
            'case_id', 'term_id', 'term_revision', 'request_key',
            'request_note', 'due_on', 'assigned_to',
        ]);
        $data = $request->validate([
            'case_id' => ['required', 'integer', 'min:1'],
            'term_id' => ['required', 'integer', 'min:1'],
            'term_revision' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
            'request_note' => ['required', 'string', 'min:10', 'max:5000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $result = $this->reviews->create($data, $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function assign(Request $request, int $review): JsonResponse
    {
        $this->cases->restrict($request, ['revision', 'assigned_to']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'assigned_to' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        return $this->json($this->reviews->assign($review, $data, $request));
    }

    public function decide(Request $request, int $review): JsonResponse
    {
        $this->cases->restrict($request, ['revision', 'decision', 'note']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['reviewed', 'changes_requested'])],
            'note' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        return $this->json($this->reviews->decide($review, $data, $request));
    }

    public function comment(Request $request, int $review): JsonResponse
    {
        $this->cases->restrict($request, ['body']);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:3000']]);
        return $this->json($this->reviews->comment($review, $data, $request));
    }

    public function withdraw(Request $request, int $review): JsonResponse
    {
        $this->cases->restrict($request, ['revision', 'note']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:10', 'max:3000'],
        ]);
        return $this->json($this->reviews->withdraw($review, $data, $request));
    }

    public function events(Request $request, int $review): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->reviews->events($review));
    }

    public function snapshot(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->snapshot($review)]);
    }

    public function integrity(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->integrity($review)]);
    }
}
