<?php

namespace App\Repositories;

use App\Contracts\UserInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRepository extends CacheRepository implements UserInterface
{

    /**
     * All the cache keys for a user object.
     *
     * @var arr
     */
    protected $cacheKeys = [
        '{{id}}',
        '{{id}}.data',
        'email.{{email}}',
    ];

    /**
     * Find a user by their id.
     *
     * @param   int     $id
     * @param   bool    $cache
     * @return  User
     */
    public function find($id, $cache = true)
    {

        if ($cache) {

            $user = $this->remember($id, function() use ($id) {
                return $this->find($id, false);
            });

        } else {
            $user = User::find($id);
        }

        return $user;
    }

    /**
     * Create a new user and immediately cache it.
     *
     * @param   arr     $data
     * @return  User
     */
    public function create($data = [])
    {
        // cleanup the data
        $data = $this->formatData($data);

        $user = User::create($data);

        // find the user to set the cache and ensure we have all the user fields
        $user = $this->find($user->id);

        return $user;
    }

    /**
     * Update a user. The $user parameter can be the full user object or an id.
     *
     * @param   User    $user
     * @param   arr     $data
     * @return  User
     */
    public function update($user, $data = [])
    {
        // no user, assume it's an id
        if (!is_object($user)) {
            $user = $this->find($user);
        }

        if ($user) {

            // clean up the data
            $data = $this->formatData($data);

            // update the user and recache it immediately
            $user->update($data);

            // bust the cache and reset it
            $this->bustCache($user);
            $user = $this->find($user->id);
        }

        return $user;
    }

    /**
     * Get the data for a User. This is ideal for pulling extra data you might
     * send in an API response already formatted.
     *
     * @param   User    $user
     * @param   bool    $cache
     * @return  arr
     */
    public function getData($user, $cache = true)
    {
        if (!is_object($user)) {
            $user = $this->find($user);
        }

        if (!$user) {
            return null;
        }

        if ($cache) {

            $key = $user->id . '.data';

            $data = $this->remember($key, function() use ($user, $request) {
                return $this->getData($user, $request, false);
            });

        } else {

            $data = $user->toArray();

            // TODO: add more data here as necessary
        }

        return $data;
    }

    /**
     * Find a user by their email address.
     *
     * @param   str     $email
     * @param   bool    $cache
     * @return  User
     */
    public function findByEmail($email, $cache = true)
    {
        if ($cache) {

            $key = 'email.' . $this->slug($email);

            $user = $this->remember($key, function() use ($email) {
                return $this->findByEmail($email, false);
            });

        } else {

            $user = User::where('email', trim($email))->first();

        }

        return $user;
    }
}
