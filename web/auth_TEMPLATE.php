<?php
/* uncomment if whitelisted
$allowedUsers = [
    "user@domain.nl"
];
*/

function nameForUser($user)
{
    //special cases
    switch (strtolower($user)) {
        case "user@domain.nl":
            return "name";
    }

    //individual lists
    return $user->name;
}