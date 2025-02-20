<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Chapter;
use App\Models\Image;
use App\Models\StoryView;
use App\Models\LicenseImage;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\StoryResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StoryController extends Controller
{
    public function index(Request $request)
    {
        // Log::info('Request data: ', $request->all());

        $categories = $request->input('categories_id');
        if (is_string($categories)) {
            $categories = json_decode($categories, true);
        }

        $stories = Story::with(['chapters', 'categories', 'author', 'favouritedByUsers', 'usersView'])
            ->withCount('storyViews')
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('active', $request->is_active);
            })
            ->when($request->has('search_string'), function ($query) use ($request) {
                $search = $request->search_string;
                $query->where('title', 'like', "%{$search}%");
            })
            ->when(!empty($categories), function ($query) use ($categories) {
                $query->whereHas('categories', function ($q) use ($categories) {
                    $q->whereIn('categories.category_id', $categories);
                });
            })
            ->when($request->has('is_complete'), function ($query) use ($request) {
                $query->where('is_complete', $request->is_complete == 1 ? 1 : 0);
            })
            ->when($request->has('user_id'), function ($query) use ($request) {
                $query->where('author_id', $request->user_id);
            })
            ->when($request->has('is_story_new') && $request->is_story_new == 1, function ($query) {
                $query->orderBy('created_at', 'desc');
            }, function ($query) {
                $query->orderBy('story_views_count', 'desc');
            })
            ->paginate(5);

        return StoryResource::collection($stories);
    }

    public function store(Request $request)
    {
        $story = new Story();
        $story->title = $request->title;
        $story->author_id = $request->author_id;
        $story->summary = $request->summary;
        $story->active = 0;
        $story->save();

        $story->categories()->attach($request->category_ids);

        $chapter = new Chapter();
        $chapter->story_id = $story->story_id;
        $chapter->title = $request->title;
        $chapter->chapter_number = 1;
        $chapter->save();

        if ($request->hasFile('chapter_image')) {
            $files = $request->file('chapter_image');
            $mainFolder = $story->story_id;
            $storagePath = 'public/stories/' . $mainFolder . '/1';

            // Đảm bảo thư mục tồn tại, nếu không sẽ tạo thư mục
            if (!Storage::exists($storagePath)) {
                Storage::makeDirectory($storagePath);
            }
            $count = 0;
            foreach ($files as $file) {
                $filename = "1" . '_img_chapter_' . $count++ . '.' . 'jpg'; // Đổi tên file
                $path = $file->storeAs($storagePath, $filename);

                //Save image
                $image = new Image();
                $image->path = $path;
                $image->story_id = $story->story_id;
                $image->chapter_id = $chapter->chapter_id;
                $image->is_cover_image = false;
                $image->save();
                //

                Log::info('File stored at: ' . $path);
            }
        }

        if ($request->hasFile('license_image')) {
            $files = $request->file('license_image');
            $mainFolder = $story->story_id;
            $storagePath = 'public/stories/' . $mainFolder . '/license';

            // Đảm bảo thư mục tồn tại, nếu không sẽ tạo thư mục
            if (!Storage::exists($storagePath)) {
                Storage::makeDirectory($storagePath);
            }
            $count = 0;
            foreach ($files as $file) {
                $filename = "$story->story_id" . '_img_document_' . $count++ . '.' . 'jpg'; // Đổi tên file
                $path = $file->storeAs($storagePath, $filename);

                //Save image
                $image = new LicenseImage();
                $image->path = $path;
                $image->story_id = $story->story_id;
                $image->chapter_id = $chapter->chapter_id;
                $image->save();
                //

                Log::info('File stored at: ' . $path);
            }
        }

        if ($request->hasFile('cover_image')) {
            $file = $request->file('cover_image');
            $mainFolder = $story->story_id;
            Log::info('Story id: ' . $mainFolder);
            $storagePath = 'public/stories/' . $mainFolder; //. $mainFolder;
            // Đảm bảo thư mục tồn tại, nếu không sẽ tạo thư mục
            if (!Storage::exists($storagePath)) {
                Storage::makeDirectory($storagePath);
            }
            $path = $file->storeAs($storagePath, "$mainFolder" . "_$story->title" . '.jpg');
            //Save image
            $image = new Image();
            $image->path = $path;
            $image->story_id = $story->story_id;
            $image->chapter_id = $chapter->chapter_id;
            $image->is_cover_image = true;
            $image->save();
            //
        }
        return response()->json($story, 201);
    }

    public function show($id)
    {
        $story = Story::with(['chapters', 'categories', 'author', 'favouritedByUsers', 'usersView'])->find($id);
        if ($story)
            return new StoryResource($story);
        else
            return response()->json(["message" => "Không có"], 401);
    }

    public function update(Request $request, $id)
    {
        $story = Story::findOrFail($id);

        $validatedData = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'author_id' => 'sometimes|required|exists:users,user_id',
            'summary' => 'nullable|string',
        ]);

        $story->update($validatedData);

        return response()->json($story, 200);
    }

    public function destroy($id)
    {
        Story::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function approveStory($id)
    {
        Log::info('Id truyện: ' . $id);
        $story = Story::where('story_id', $id)->first();
        if ($story) {
            $story->active = 1;
            $story->save();
            $author = User::where('user_id', $story->author_id)->first();
            if ($author && $author->role === 'author') {
                // Tạo thông báo
                Notification::create([
                    'user_id' => $author->user_id,
                    'title' => 'Yêu cầu duyệt truyện',
                    'message' => 'Truyện ' . $story->title . ' của bạn đăng đã được phê duyệt!',
                    'is_read' => false,
                    'story_id' => $story->story_id,
                ]);

                // Trả về thông báo đã gửi
                return response()->json([
                    "message" => "Phê duyệt thành công và thông báo đã được gửi đến user_id: " . $author->user_id
                ], 200);
            }
            return response()->json(["message" => "Phê duyệt thành công nhưng không tìm thấy tác giả"], 200);
        } else {
            return response()->json(["message" => "Câu chuyện không tồn tại"], 404);
        }
    }

    public function disableStory($id)
    {
        Log::info('Id truyện: ' . $id);
        $story = Story::where('story_id', $id)->first();
        if ($story) {
            $story->active = 2;
            $story->save();
            $author = User::where('user_id', $story->author_id)->first();
            if ($author && $author->role === 'author') {
                // Tạo thông báo
                Notification::create([
                    'user_id' => $author->user_id,
                    'title' => 'Thông báo !',
                    'message' => 'Truyện ' . $story->title . ' của bạn đã được vô hiệu hóa do không cập nhật trong thời gian dài!',
                    'is_read' => false,
                    'story_id' => $story->story_id,
                ]);

                // Trả về thông báo đã gửi
                return response()->json([
                    "message" => "Phê duyệt thành công và thông báo đã được gửi đến user_id: " . $author->user_id
                ], 200);
            }
            return response()->json(["message" => "Vô hiệu hóa truyện thành công"], 200);
        } else {
            return response()->json(["message" => "Truyện không tồn tại"], 404);
        }
    }
    public function noApproveStory($id)
    {
        Log::info('Id truyện: ' . $id);
        $story = Story::where('story_id', $id)->first();
        if ($story) {
            $story->active = 3;
            $story->save();
            $author = User::where('user_id', $story->author_id)->first();
            if ($author && $author->role === 'author') {
                // Tạo thông báo
                Notification::create([
                    'user_id' => $author->user_id,
                    'title' => 'Yêu cầu duyệt truyện',
                    'message' => 'Truyện ' . $story->title . ' của bạn đã bị từ chối phê duyệt do không đủ yêu cầu',
                    'is_read' => false,
                    'story_id' => $story->story_id,
                ]);

                // Trả về thông báo đã gửi
                return response()->json([
                    "message" => "Phê duyệt thành công và thông báo đã được gửi đến user_id: " . $author->user_id
                ], 200);
            }
            return response()->json(["message" => "Hủy phê truyện thành công"], 200);
        } else {
            return response()->json(["message" => "Truyện không tồn tại"], 404);
        }
    }



    public function completedStory($id)
    {
        Log::info('Id truyện: ' . $id);
        $story = Story::where('story_id', $id)->first();
        if ($story) {
            $story->is_complete = 1;
            $story->save();
            return response()->json(["message" => "Bạn đã hoàn thành truyện"], 200);
        } else {
            return response()->json(["message" => "Truyện không tồn tại"], 404);
        }
    }

    public function addView(Request $request, $story_id)
    {
        $user_id = $request->user_id;

        $storyView = StoryView::where('story_id', $story_id)
            ->where('user_id', $user_id)
            ->first();

        if (!$storyView) {
            StoryView::create([
                'story_id' => $story_id,
                'user_id' => $user_id,
                'view_count' => 1,
                'last_viewed' => now()
            ]);
        }

        return response()->json(['message' => 'Xem truyện thành công'], 200);
    }
    public function getUserViewedStories($user_id)
    {
        $viewedStories = StoryView::where('user_id', $user_id)
            ->with(['story' => function ($query) {
                $query->with(['chapters' => function ($query) {
                    $query->with(['chapterImages', 'comments', 'notifications']);
                }, 'categories', 'author', 'favouritedByUsers', 'usersView'])
                    ->withCount('storyViews');
            },])

            ->get()
            ->map(function ($storyView) {
                return $storyView->story;
            });
        return response()->json(['data' => StoryResource::collection($viewedStories)], 200);
    }
    public function getTotalViews($story_id)
    {
        $totalViews = StoryView::where('story_id', $story_id)->count();

        return response()->json(['total_views' => $totalViews], 200);
    }

    public function totalStories()
    {
        $totalStories = Story::where('active', 1)->count();
        $totalStories = Story::count();
        return response()->json(['total_stories' => $totalStories], 200);
        return $totalStories;
    }

    public function getNewStories(Request $request)
    {
        $timePeriod = $request->input('time_period', 'week');
        $now = Carbon::now();

        if ($timePeriod === 'week') {
            $startDate = $now->subWeek();
        } elseif ($timePeriod === 'month') {
            $startDate = $now->subMonth();
        } else {
            return response()->json(['message' => 'Tham số time_period không hợp lệ. Chỉ chấp nhận "week" hoặc "month".'], 400);
        }

        $newUsersCount = Story::where('created_at', '>=', $startDate)->where('active', 1)->count();

        return response()->json(['new_story_count' => $newUsersCount]);
    }
}
