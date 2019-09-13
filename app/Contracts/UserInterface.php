<?php

namespace App\Contracts;

interface UserInterface
{

    public function find($id, $cache = true);

    public function create($data = []);

    public function update($user, $data = []);

    public function getData($user, $cache = true);

    public function findByEmail($email, $cache = true);

}
