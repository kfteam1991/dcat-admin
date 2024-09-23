<?php

namespace Dcat\Admin\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TinymceController
{
    public function upload(Request $request)
    {
        $file = $request->file('file');
        $dir = trim($request->get('dir'), '/');
        $disk = $this->disk();

        $newName = $this->generateNewName($file);

        $disk->putFileAs($dir, $file, $newName);

        return ['location' => $disk->url("{$dir}/$newName")];
    }

    /**
     * 删除图片
     *
     * @return void
     */
    public function delete(Request $request)
    {
        $imagePath = urldecode($request->input('image'));//路径解码
      // 提取相对路径
        $imagePath = str_replace(url('storage/'), '', $imagePath); 
       // 使用 DIRECTORY_SEPARATOR 确保路径兼容性
        $imagePath = 'public' . DIRECTORY_SEPARATOR . $imagePath;
        // 删除图片逻辑
        if (Storage::exists($imagePath)) {
            $result = Storage::delete($imagePath);
            return response()->json(['success' =>  $result, 'message' => 'Image deleted successfully.']);
        }
    
        return response()->json(['success' => false, 'message' => 'Image not found.'], 404);
    }

    protected function generateNewName(UploadedFile $file)
    {
        return uniqid(md5($file->getClientOriginalName())).'.'.$file->getClientOriginalExtension();
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem|FilesystemAdapter
     */
    protected function disk()
    {
        $disk = request()->get('disk') ?: config('admin.upload.disk');

        return Storage::disk($disk);
    }
}
