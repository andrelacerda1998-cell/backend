<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

class AssignUserDevUserCommand extends Command
{
    protected $signature = 'assign:dev-user';

    protected $description = 'Command description';

    public function handle(): void
    {
        $user = search('Choose a user', function ($q) {
           if ($q) {
               return User::where('name', 'like', "%$q%")->whereHas('roles', function ($query){
                   return $query->where('name', 'admin')->orWhere('name', 'super-admin');
               })->get()->pluck('name', 'id')->toArray();
           }
           return User::whereHas('roles', function ($query){
               return $query->where('name', 'admin')->orWhere('name', 'super-admin');
           })->get()->pluck('name', 'id')->toArray();
        });

        $user = User::find($user);
        $role = Role::findOrCreate('developer', 'web');

        $user->assignRole($role);
        $user->save();

        $this->info('User assigned successfully');
    }
}
