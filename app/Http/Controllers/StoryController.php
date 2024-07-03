<?php

namespace App\Http\Controllers;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Chapter;
use App\Models\Image;
use App\Models\LicenseImage;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\StoryResource;


class StoryController extends Controller
{
    public function index(Request $request)
    {
        $stories = Story::with(['chapters', 'categories', 'author', 'favouritedByUsers'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('active', $request->is_active == 1 ? 1 : 0);
            })
            ->when($request->has('search_string'), function ($query) use ($request) {
                $search = $request->search_string;
                $query->where('title', 'like', "%{$search}%");
            })
            ->when($request->has('categories_id'), function ($query) use ($request) {
                $categories = $request->categories_id;
                $query->whereHas('categories', function ($q) use ($categories) {
                    $q->whereIn('categories.category_id', $categories);
                });
            })
            ->paginate();

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
        $chapter->content = '';
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
        $story = Story::with(['chapters', 'categories', 'author', 'favouritedByUsers'])->find($id);
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
            return response()->json(["message" => "Phê duyệt thành công"], 200);
        } else {
            return response()->json(["message" => "Câu chuyện không tồn tại"], 404);
        }
    }
}
