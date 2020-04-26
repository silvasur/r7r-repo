<?php
/*
 * File: ratatoeskr/sys/pwhash.php
 *
 * Hashing passwords
 *
 * License:
 * This file is part of Ratatöskr.
 * Ratatöskr is licensed unter the MIT / X11 License.
 * See "ratatoeskr/licenses/ratatoeskr" for more information.
 */

/*
 * Class: PasswordHash
 * Contains static functions for password hashes.
 * Is just used as a namespace, can not be created.
 *
 * It should be fairly difficult to break these salted hashes via bruteforce attacks.
 */
class PasswordHash
{
    private function __construct() {} /* Prevent construction */

    private static $saltlen_min = 20;
    private static $saltlen_max = 30;
    private static $iterations_min = 200;
    private static $iterations_max = 1000;

    private static function hash($data, $salt, $iterations)
    {
        $hash = $data . $salt;
        for($i = $iterations ;$i--;)
            $hash = sha1($data . $hash . $salt, (bool) $i);
        return $iterations . '$' . bin2hex($salt) . '$' . $hash;
    }

    /*
     * Function: create
     * Create a password hash string.
     *
     * Parameters:
     *  $password - The password (or other data) to hash.
     *
     * Returns:
     *  The salted hash as a string.
     */
    public static function create($password)
    {
        $salt = "";
        $saltlen = mt_rand(self::$saltlen_min, self::$saltlen_max);
        for($i = 0; $i < $saltlen; $i++)
            $salt .= chr(mt_rand(0,255));
        return self::hash($password, $salt, mt_rand(self::$iterations_min, self::$iterations_max));
    }

    /*
     * Function: validate
     * Validate a salted hash.
     *
     * Parameters:
     *  $password - The password to test.
     *  $pwhash   - The hash to test against.
     *
     * Returns:
     *  True, if $password was correct, False otherwise.
     */
    public static function validate($password, $pwhash)
    {
        list($iterations, $hexsalt, $hash) = explode('$', $pwhash);
        return self::hash($password, pack("H*", $hexsalt), $iterations) == $pwhash;
    }
}
