<?php

namespace App\Http\Controllers;

use App\Models\CustomRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Mail\CustomRequestMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;


class CustomRequestController extends Controller
{
   public function index()
   {
      $customRequests = CustomRequest::with(['images', 'category.images'])
         ->where('user_id', Auth::id())
         ->orderBy('created_at', 'desc')
         ->get();

      return response()->json($customRequests);
   }

   public function store(Request $request): JsonResponse
   {
      $validator = Validator::make($request->all(), [
         'message' => 'required|string|max:1000',
         'category_id' => 'required|exists:categories,id',
         'images' => 'array', // S'assurer que 'images' est un tableau
         'images.*' => 'nullable|image|max:10240', // Chaque fichier doit être une image valide et <= 10 MB
      ]);

      if ($validator->fails()) {
         return response()->json(['errors' => $validator->errors()], 422);
      }

      $customRequest = CustomRequest::create([
         'email' => Auth::user()->email,
         'message' => $request->message,
         'category_id' => $request->category_id,
         'user_id' => $request->user()->id,
      ]);

      // Gestion des images
      if ($request->hasFile('images')) {
         foreach ($request->file('images') as $image) {
            $path = $image->store('custom_requests', 's3');
            $url = Storage::disk('s3')->url($path);

            $customRequest->images()->create([
               'url' => $url,
               'type' => 'public',
            ]);
         }
      }

      // Charger les relations pour l'email
      $customRequest->load(['images', 'category']);

      // Préparer les données pour l'email
      $emailData = [
         'user_name' => Auth::user()->name,
         'user_email' => Auth::user()->email,
         'message' => $customRequest->message,
         'category' => $customRequest->category->name,
         'images' => $customRequest->images->pluck('url')->toArray(),
      ];

      Log::info('Données pour l’email', $emailData);

      // Envoyer l'email
      Mail::to(config('mail.from.address'))->send(new CustomRequestMail($emailData));

      return response()->json($customRequest->load('images'), 201);
   }

   public function show(CustomRequest $customRequest)
   {
      $customRequest->load(['images', 'category.images']);
      return response()->json($customRequest);
   }
}
