<?php

namespace App\Http\Controllers\Api;

use App\User;
use App\User_verification;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Hash;
use Mail;
use App\Mail\registrationEmail;
use Uuid;

class ApiAuthController extends Controller {

    use AuthenticatesUsers;

    public function login(Request $request){
        if($this->attemptLogin($request)){
            $user = Auth::user();
            $user->api_token = str_random(60);
            $user->save();
            return response()->json([
                'api_token' => 'Bearer '.$user->api_token
            ], 200);
        }else{
            return response()->json(['status' => false, 'message' => 'Your email/password is incorrect.'],403);
        }
    }

    public function register(Request $request) {

        // Verify the Input data and return errors on failure
        $email = $request->input('email');

        $user = User::find($request->input('email'));
        if(!is_null($user)){
            return response()->json(['status' => false, 'message' => 'The email address ['.$request->input('email').'] is already in use.'],400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => false, 'message' => 'Email address supplied is invalid.'],400);
        }
        $password = $request->input('password');
        if (strlen($password) < 8) {
            return response()->json(['status' => false, 'message' => 'Password must be 8 characters or longer.'],400);
        }
        $passwordConfirm = $request->input('passwordConfirm');
        if ($password !== $passwordConfirm) {
            return response()->json(['status' => false, 'message' => 'Passwords must match.'], 400);
        }

        // Make a url safe token for the user to authenticate with
        $urlCode = str_random(60);
        $urlString = env('CLIENT_HOSTNAME').'/activate/' . $urlCode;

        // Check for duplicate emails
        $user_v = User_verification::where('email', '=', $email)->first();

        // If no duplicate is found, create a new record, else refresh the token
        if (is_null($user_v)) {
            $user_verification = new User_verification();
            $user_verification->id = Uuid::generate();
            $user_verification->email = $email;
            $user_verification->token = $urlCode;
            $user_verification->name = $request->input('name');
            $user_verification->password = Hash::make($password);
            $user_verification->save();
        }else{
            $user_v->token = $urlCode;
            $user_v->save();
        }

        // Send authentication email and return success event
        Mail::to($email)->send(new registrationEmail($urlString));
        return response()->json(['status' => True, 'message' => 'Registration was successful, an activation email has been sent to the supplied email address!'], 200);

    }

    public function activate(Request $request) {

        // Is the token in the users verification table
        if($request->has('token')) {
            $token = $request->get('token');
            $user_t = User_verification::where('token', '=', $token)->first();
            if (is_null($user_t)) {
                return response()->json(['status' => false, 'message' => 'Token does not exist.'], 400);
            }
            // Is the activation within the valid time period (1 week or 604,800s)
            if (time() > strtotime($user_t->updated_at) + 604800) {
                return response()->json(['status' => false, 'message' => 'Token has expired.'], 400);
            }
            $user = new User();
            $user->name = $user_t->name;
            $user->email = $user_t->email;
            $user->password = $user_t->password;
            $user->api_token = str_random(60);
            $user->admin = false;
            $user->save();
            $user_t->delete();
            return response()->json([
                'api_token' => 'Bearer '.$user->api_token
            ], 200);
        }else{
            return response()->json(['status' => false, 'message' => 'No activation token was presented.'], 400);
        }

    }

}