<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class TaskController extends Controller
{
   public function index(Request $request)
    {
        $user = $request->user();
        
        // ЗАЩИТА: Если пользователя нет, возвращаем 401
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $this->createOverdueNotifications();
        
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 50));

        $query = Task::with([
            'assignee:id,name,email',
            'creator:id,name,email',
            'comments.user:id,name,role',
        ])->orderBy('deadline');

        // ЗАЩИТА: Проверяем роль безопасно
        if ($user->role !== 'admin') {
            $query->where('assigned_to', $user->id);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($task) => $this->transformTask($task))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();
        
        if (!$admin || $admin->role !== 'admin') {
            return response()->json(['message' => 'Только админ может ставить задачи'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|integer|exists:users,id',
            'deadline' => 'required|date',
        ]);

        $assignee = User::findOrFail($validated['assigned_to']);
        if ($assignee->role !== 'manager') {
            return response()->json(['message' => 'Задачу можно поставить только менеджеру'], 422);
        }

        $task = Task::create([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'deadline' => $validated['deadline'],
            'status' => 'pending',
        ]);

        UserNotification::create([
            'user_id' => $assignee->id,
            'type' => 'task_created',
            'title' => 'Новая задача',
            'message' => "Вам поставили задачу: {$task->title}",
            'meta' => ['task_id' => $task->id],
        ]);

        $task->load(['assignee:id,name,email', 'creator:id,name,email', 'comments.user:id,name,role']);

        return response()->json($this->transformTask($task), 201);
    }
    public function start(Request $request, Task $task)
    {
        $user = $request->user();
        if ((int) $task->assigned_to !== (int) $user->id) {
            return response()->json(['message' => 'Можно запускать только свои задачи'], 403);
        }
        if ($task->status === 'completed') {
            return response()->json(['message' => 'Задача уже закрыта'], 422);
        }

        $task->update(['status' => 'in_progress']);
        $task->refresh();
        $task->load(['assignee:id,name,email', 'creator:id,name,email', 'comments.user:id,name,role']);

        return response()->json($this->transformTask($task));
    }

    public function complete(Request $request, Task $task)
    {
        $user = $request->user();
        if ((int) $task->assigned_to !== (int) $user->id) {
            return response()->json(['message' => 'Можно закрывать только свои задачи'], 403);
        }
        if ($task->status === 'completed') {
            return response()->json(['message' => 'Задача уже закрыта'], 422);
        }

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            UserNotification::create([
                'user_id' => $admin->id,
                'type' => 'task_completed',
                'title' => 'Задача закрыта',
                'message' => "{$user->name} закрыл задачу: {$task->title}",
                'meta' => ['task_id' => $task->id, 'manager_id' => $user->id],
            ]);
        }

        $task->refresh();
        $task->load(['assignee:id,name,email', 'creator:id,name,email', 'comments.user:id,name,role']);

        return response()->json($this->transformTask($task));
    }

    public function destroy(Request $request, Task $task)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json(['message' => 'Только админ может удалять задачи'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Задача удалена']);
    }

    public function comment(Request $request, Task $task)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        $isAssignee = (int) $task->assigned_to === (int) $user->id;

        if (!$isAdmin && !$isAssignee) {
            return response()->json(['message' => 'Нет доступа к комментариям этой задачи'], 403);
        }

        $validated = $request->validate([
            'comment' => 'required|string|max:2000',
        ]);

        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'comment' => $validated['comment'],
        ]);

        if ($isAdmin) {
            UserNotification::create([
                'user_id' => $task->assigned_to,
                'type' => 'task_comment',
                'title' => 'Комментарий к задаче',
                'message' => "Админ добавил комментарий к задаче: {$task->title}",
                'meta' => ['task_id' => $task->id],
            ]);
        } else {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                UserNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'task_comment',
                    'title' => 'Комментарий менеджера',
                    'message' => "{$user->name} оставил комментарий к задаче: {$task->title}",
                    'meta' => ['task_id' => $task->id],
                ]);
            }
        }

        $task->refresh();
        $task->load(['assignee:id,name,email', 'creator:id,name,email', 'comments.user:id,name,role']);

        return response()->json($this->transformTask($task));
    }

public function notifications(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $this->createOverdueNotifications();
        
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 50));

        $query = UserNotification::where('user_id', $user->id)->latest();
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unread_count' => UserNotification::where('user_id', $user->id)->where('is_read', false)->count(),
        ]);
    }
    public function readNotification(Request $request, UserNotification $notification)
    {
        $user = $request->user();
        if ((int) $notification->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Нет доступа'], 403);
        }

        $notification->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    private function createOverdueNotifications(): void
    {
        $tasks = Task::where('status', '!=', 'completed')
            ->where('deadline', '<', now())
            ->whereNull('overdue_notified_at')
            ->get();

        foreach ($tasks as $task) {
            $usersToNotify = User::whereIn('id', [$task->assigned_to, $task->created_by])->get();
            foreach ($usersToNotify as $target) {
                UserNotification::create([
                    'user_id' => $target->id,
                    'type' => 'task_overdue',
                    'title' => 'Просроченная задача',
                    'message' => "Истек дедлайн задачи: {$task->title}",
                    'meta' => ['task_id' => $task->id],
                ]);
            }

            $task->update(['overdue_notified_at' => now()]);
        }
    }

    private function transformTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'deadline' => optional($task->deadline)->toIso8601String(),
            'completed_at' => optional($task->completed_at)->toIso8601String(),
            'is_overdue' => $task->status !== 'completed' && $task->deadline && $task->deadline->isPast(),
            'assigned_to' => $task->assigned_to,
            'created_by' => $task->created_by,
            'assignee' => $task->assignee,
            'creator' => $task->creator,
            'comments' => $task->comments->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'comment' => $comment->comment,
                    'created_at' => optional($comment->created_at)->toIso8601String(),
                    'user' => $comment->user,
                ];
            })->values(),
        ];
    }
}
