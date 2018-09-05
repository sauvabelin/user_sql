<?php

namespace OCA\UserSQL\Crypto;

require_once __DIR__ . "/../../vendor/autoload.php";

use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

class SymfonySha512 implements IPasswordAlgorithm
{
    /**
     * @var MessageDigestPasswordEncoder
     */
    private $encoder;

    /**
     * Get the hash algorithm name.
     * This name is visible in the admin panel.
     *
     * @return string
     */
    public function getVisibleName()
    {
        return "symfony sha512";
    }

    /**
     * Hash given password.
     * This value is stored in the database, when the password is changed.
     *
     * @param String $password The new password.
     *
     * @return boolean True if the password was hashed successfully, false otherwise.
     */
    public function getPasswordHash($password)
    {
        return false;
    }

    /**
     * Check password given by the user against hash stored in the database.
     *
     * @param String $password Password given by the user.
     * @param String $dbHash Password hash stored in the database.
     *
     * @return boolean True if the password is correct, false otherwise.
     */
    public function checkPassword($password, $dbHash)
    {
        if(!$this->encoder)
            $this->encoder = new MessageDigestPasswordEncoder('sha512', true, 5000);

        if(strlen($password) < 42)
            return false;

        $salt       = substr($dbHash, -40);
        $password   = substr($dbHash, 0, strlen($password) - 40);

        return $this->encoder->isPasswordValid($dbHash, $password, $salt);
    }
}