<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use \App\Models\User;
use \App\Http\Resources\User as UserResource;
use \App\Http\Responses\UserResponse;
use \App\Http\Requests\SignUpRequest;
use \App\Http\Requests\CreateAccountRequest;
use \App\Helpers\Hash;
use \Illuminate\Support\Facades\DB;
use \App\Models\OrganizationUser;
use \App\Models\UserProfileVariant;
use \App\Models\UserProfileVariantGeneralInfo as UserProfileVariantGeneralInfoModel;
use \Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * @OA\Post(
     *      path="/api/users/current",
     *      operationId="current",
     *      tags={"Users"},
     *      security={{"bearer":{}}},  
     *      summary="Get logged user",
     *      description="Return current logged user",
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              type="item",
     *              example={ 
     *                    "id": 1, 
     *                     "first_name": "John",
     *                    "last_name": "Sena",
     *                    "email": "email@example.com",
     *                   "created": "2021-01-26T10:48:18.000000Z", 
     *                  "updated": "2021-01-26T10:48:18.000000Z",
     *                   "photo_url": "https://www.gravatar.com/avatar/97a4a30ad7abdb33b5863ff76b19375e.jpg?s=200&d=mp",
     *                  "access": {
     *                      "create_user", "update_user"
     *                  }
     *              }    
     *          )
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden"
     *      )
     *     )
     */
    public function current(Request $request)
    {
        if (!$request->user()) {

            return response()->json([], 403);
        }
        $organization = $request->get('organization');

        $organizationUser = OrganizationUser::where('user_id', $request->user()->id)->where('organization_id', $organization->id)->first();

        return UserResponse::toResponse($organizationUser);
    }

    /**
     * @OA\Get(
     *      path="/api/users/{id}",
     *      operationId="user",
     *      security={{"bearer":{}}},  
     *      tags={"Users"},
     *      summary="Get user by id",
     *      description="Return user by id",
     *   @OA\Parameter(
     *          name="id",
     *          description="User id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(
     *              type="integer"
     *          )
     *      ),
     *   @OA\Response(
     *      response=404,
     *      description="User not found"
     *   ),
     *   @OA\Response(
     *       response=200,
     *       description="Success response",
     *       @OA\JsonContent(
     *            example={ "id": 1, "first_name": "John", "last_name": "Sena", "email": "email@example.com", "created": "2021-01-26T10:48:18.000000Z",
     *           "updated": "2021-01-26T10:48:18.000000Z", "photo_url": "https://www.gravatar.com/avatar/97a4a30ad7abdb33b5863ff76b19375e.jpg?s=200&d=mp",
     *              "profile_variants_count":  1,
     *               "access": {"create_user", "update_user"}
     *           }    
     *       ),
     *           
     *   )
     *)
     */
    public function user($domain, $id, Request $request)
    {
        $user = User::find($id);
    
        if (!$user) {

            return response()->json([], 404);
        }

        $organizationUser = OrganizationUser::where(
            [
                'user_id' => $user->id,
                'organization_id' => $request->get('organization')->id
            ])->first();   

            $organizationUser = OrganizationUser::where(
                [
                    'user_id' => $user->id,
                    'organization_id' => $request->get('organization')->id
                ])->first();        

            if (!$organizationUser) {
                return response()->json([], 404);
            }            
            
        return UserResponse::toResponse($organizationUser);
    }

    /**
     * @OA\Post(
     *      path="/api/create-account",
     *      operationId="User::createAccount",  
     *      tags={"Create Account"},
     *      summary="Create account",
     *      description="Create account",
     *      @OA\Parameter(
     *          name="work_email",
     *          description="Work E-mail",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="linkedin",
     *          description="Linkedin link",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="first_name",
     *          description="First name",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="last_name",
     *          description="Last name",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="location",
     *          description="Location",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="job_title",
     *          description="Job title",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *    @OA\Parameter(
     *          name="image",
     *          description="User image",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="file"
     *          )
     *      ),
     *  @OA\Parameter(
     *          name="password",
     *          description="Password",
     *          required=true,
     *          in="query",
    *          @OA\Schema(
     *              type="string",
     *              format="password"
     *          )
     *      ),
     *   @OA\Parameter(
     *          name="confirm_password",
     *          description="Confirm password",
     *          required=true,
     *          in="query",
     *          @OA\Schema(
     *              type="string",
     *              format="password"
     *          )
     *      ),
     *   @OA\Response(
     *       response=201,
     *       description="Created with success",
     *   ), 
     *   @OA\Response(
     *       response=500,
     *       description="Save error(database transaction error)",
     *   ),
     *   @OA\Response(
     *       response=422,
     *       description="Validation Error",
     *       @OA\JsonContent(
     *           example={
     *               "message": "The given data was invalid.",
     *               "errors": {
     *                   "name": {
     *                       "The name field is required."
     *                   }
     *               }
     *           }
     *       )
     *   )
     *)
     */
    public function createAccount(CreateAccountRequest $request)
    {
        DB::beginTransaction();
        /** TODO: implement phone save */
        try {
            $user = new User();
            $user->email = $request->get('work_email');
            $user->first_name = $request->get('first_name');
            $user->last_name = $request->get('last_name');
            $user->password = Hash::encode($request->get('password'));

            $user->save();

            $member = new OrganizationUser();
            $member->user_id = $user->id;
            $organization = $request->get('organization');
            $member->organization_id = $organization->id;
            $member->role = OrganizationUser::ROLE_MEMBER;
            $member->visibility = OrganizationUser::VISIBILITY_PUBLIC;
            $member->status = OrganizationUser::STATUS_ACTIVE;
            $member->organization_group_id = 0;
            $member->save();

            $userProfileVariant = new UserProfileVariant();
            $userProfileVariant->organization_user_id = $member->id;
            $userProfileVariant->title = 'Default Profile Variant';
            $userProfileVariant->is_default = 1;
            $userProfileVariant->profile_path = Str::slug($user->first_name . ' ' . $user->last_name);
            $userProfileVariant->save();

            $generalInfo = new UserProfileVariantGeneralInfoModel();
            $generalInfo->user_profile_variant_id = $userProfileVariant->id;
            $generalInfo->first_name = $user->first_name;
            $generalInfo->location = $request->get('location');
            $generalInfo->last_name = $user->last_name;

            $generalInfo->title = $request->get('job_title');

            if ($request->hasFile('image')) {
                $fileId = $request->file('image')->storePublicly('/', 's3');
                $fileUrl = Storage::cloud()->url($fileId);
                $generalInfo->photo_url = $fileUrl;
                $generalInfo->photo_url_original = $fileUrl;
            }

            $generalInfo->save();

            DB::commit();
        } catch (\Exception $e) {

            return response()->json([
                'message' => $e->getMessage()
            ], 500);

            DB::rollBack();
        }

        return response()->json([
            'id' => $member->id ?? 0
        ], 201);
    }
}
