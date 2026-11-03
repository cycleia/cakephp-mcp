<?php
declare(strict_types=1);

namespace CakeMcp\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class UsersFixture extends TestFixture
{
    public $table = 'users';

    public $records = [
        ['id' => 1, 'email' => 'admin@site.test', 'username' => 'admin', 'password' => '$2y$10$adminhash', 'role' => 'admin', 'active' => 1],
        ['id' => 2, 'email' => 'member@site.test', 'username' => 'member', 'password' => '$2y$10$memberhash', 'role' => 'user', 'active' => 1],
        ['id' => 3, 'email' => 'former@site.test', 'username' => 'former', 'password' => '$2y$10$formerhash', 'role' => 'admin', 'active' => 0],
        ['id' => 4, 'email' => 'staff@site.test', 'username' => 'staff', 'password' => '$2y$10$staffhash', 'role' => 'staff', 'active' => 1],
    ];
}
