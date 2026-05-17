<?php

namespace OCA\UserSQL\Crypto;

/**
 * NetBS / legacy Symfony "MessageDigestPasswordHasher" with algorithm=sha512,
 * iterations=5000, encodeHashAsBase64=true. Inlined to avoid a runtime
 * dependency on the symfony/password-hasher (or symfony/security) package.
 *
 * Reference: symfony/password-hasher v6.4.32
 *   https://github.com/symfony/password-hasher/blob/v6.4.32/Hasher/MessageDigestPasswordHasher.php
 *   https://github.com/symfony/password-hasher/blob/v6.4.32/Hasher/PasswordHasherFactory.php#L209-L216
 *
 * The factory dispatches `algorithm: sha512` (as configured in netBS5's
 * security.yaml) to MessageDigestPasswordHasher('sha512', true, 5000); this
 * class reproduces that hasher's verify() byte-for-byte.
 */
class SymfonySha512 extends AbstractAlgorithm
{
    private const ALGORITHM = 'sha512';
    private const ITERATIONS = 5000;
    private const MAX_PASSWORD_LENGTH = 4096;
    private const EXPECTED_HASH_LENGTH = 88; // base64(sha512 raw) = ceil(64/3)*4

    protected function getAlgorithmName()
    {
        return "NETBS_Encoding";
    }

    /**
     * Read-only backend: password changes happen on the netBS5 side, never
     * here. Returning false matches the previous behaviour and disables the
     * "change password" path in Nextcloud for users coming from this backend.
     */
    public function getPasswordHash($password, $salt = null)
    {
        return false;
    }

    /**
     * Reproduces Symfony\Component\PasswordHasher\Hasher\MessageDigestPasswordHasher::verify
     * with ('sha512', true, 5000).
     */
    public function checkPassword($password, $dbHash, $salt = null)
    {
        if (!is_string($password) || !is_string($dbHash)) {
            return false;
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return false;
        }
        if (strlen($dbHash) !== self::EXPECTED_HASH_LENGTH) {
            return false;
        }
        if (strpos($dbHash, '$') !== false) {
            return false;
        }

        $salted = $this->mergePasswordAndSalt($password, $salt);
        if ($salted === null) {
            return false;
        }

        $digest = hash(self::ALGORITHM, $salted, true);
        for ($i = 1; $i < self::ITERATIONS; $i++) {
            $digest = hash(self::ALGORITHM, $digest . $salted, true);
        }

        return hash_equals($dbHash, base64_encode($digest));
    }

    private function mergePasswordAndSalt($password, $salt)
    {
        if ($salt === null || $salt === '') {
            return $password;
        }
        if (strpos($salt, '{') !== false || strpos($salt, '}') !== false) {
            return null;
        }
        return $password . '{' . $salt . '}';
    }
}
