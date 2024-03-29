<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\File;
use App\Notifications\SendFile;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Streaming\Representation;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/email-file', function (Request $request) {
  $uploadedFile = $request->video;
  $file = File::firstOrCreate([
    'name' => $uploadedFile->getClientOriginalName(),
    'mime_type' => $uploadedFile->getClientMimeType(),
    'size' => $uploadedFile->getSize()
  ]);
  $file->email = $request->email;
  $file->save();
  $file->notify(new SendFile($uploadedFile));
  return response()->json($file);
});

Route::post('/upload-file-data', function (Request $request) {
  $uploadedFile = $request->video;
  $path = Storage::putFile('videos', $uploadedFile);
  $bucket = env('AWS_BUCKET');
  $file = File::Create([
    'name' => $uploadedFile->getClientOriginalName(),
    'mime_type' => $uploadedFile->getClientMimeType(),
    'size' => $uploadedFile->getSize(),
    'path' => Storage::url($path)
  ]);
return response()->json($file);
});

Route::get('/video/{file}', function (File $file) {
  return response()->json($file);
});

Route::get('/video', function (Request $request) {
  return response()->json(File::all());
});

Route::post('/video', function (Video $video) {
  return response()->json($video);
});

Route::get('/get-stats', function (Request $request) {
  $size = File::all()->sum('size');
  return response()->json($size);
});

Route::get('/login/youtube', function (Request $request) {
  return Socialite::driver('youtube')->scopes(['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/drive', 'https://www.googleapis.com/auth/drive.metadata', 'https://www.googleapis.com/auth/drive.metadata.readonly'])->stateless()->redirect();
});

Route::get('/callback/youtube', function (Request $request) {
  $user = Socialite::driver('youtube')->stateless()->user();
  return redirect(env('RECORDER_URL').'/#/success?token='.$user->token);
});

Route::post('/get-dash', function (Request $request) {
  $uploadedFile = $request->video;
  $path = Storage::putFile('videos', $uploadedFile);
  $bucket = env('AWS_BUCKET');
  $file = File::Create([
    'name' => $uploadedFile->getClientOriginalName(),
    'mime_type' => $uploadedFile->getClientMimeType(),
    'size' => $uploadedFile->getSize(),
    'path' => Storage::url($path)
  ]);
  $config = [
    'version'     => 'latest',
    'region'      => env('AWS_DEFAULT_REGION'), // the region of your cloud server
    'credentials' => [
        'key'    => env('AWS_ACCESS_KEY_ID'), // the key to authorize you on the server
        'secret' => env('AWS_SECRET_ACCESS_KEY'), // the secret to access to the cloud
    ]
  ];
  $s3 = new Streaming\Clouds\S3($config);

  $from_s3 = [
      'cloud' => $s3,
      'options' => [
          'Bucket' => $bucket, // name of your bucket
          'Key' => $file->path // your file name on the cloud
      ]
  ];

  $to_s3 = [
      'cloud' => $s3,
      'options' => [
          'dest' => `s3://{$bucket}/dash/`, // name of your bucket and path to content folder
          'filename' => `{$file->size}.m3u8` // name of your file on the cloud
      ]
  ];

  $ffmpeg = Streaming\FFMpeg::create();
  $video = $ffmpeg->openFromCloud($from_s3);
  $video->dash()
      ->setAdaption('id=0,streams=v id=1,streams=a') // Set the adaption.
      ->vp9() // Format of the video. Alternatives: x264() and vp9()
      ->autoGenerateRepresentations() // Auto generate representations
      ->save(null, $to_s3); // It can be passed a path to the method or it can be null
      return response()->json($video->metadata());
});

Route::post('/stream-to-youtube', function(Request $request){
  $response = Http::attach(
    'file', file_get_contents($request->file)
    )->put($request->url);
    Log::info($response->getBody());
    Log::info($request->url);
    Log::info(file_get_contents($request->file));
  return $response;
});
