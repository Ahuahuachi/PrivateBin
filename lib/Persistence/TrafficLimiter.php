<?php

/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   1.3.5
 */

namespace PrivateBin\Persistence;

use Exception;
use IPLib\Factory;
use PrivateBin\Configuration;

/**
 * TrafficLimiter
 *
 * Handles traffic limiting, so no user does more than one call per 10 seconds.
 */
class TrafficLimiter extends AbstractPersistence
{
    /**
     * time limit in seconds, defaults to 10s
     *
     * @access private
     * @static
     * @var    int
     */
    private static $_limit = 10;

    /**
     * listed ips are exempted from limits, defaults to null
     *
     * @access private
     * @static
     * @var    string|null
     */
    private static $_exemptedIp = null;

    /**
     * key to fetch IP address
     *
     * @access private
     * @static
     * @var    string
     */
    private static $_ipKey = 'REMOTE_ADDR';

    /**
     * set the time limit in seconds
     *
     * @access public
     * @static
     * @param  int $limit
     */
    public static function setLimit($limit)
    {
        self::$_limit = $limit;
    }

    /**
     * set a list of ip(ranges) as string
     *
     * @access public
     * @static
     * @param string $exemptedIps
     */
    public static function setExemptedIp($exemptedIp)
    {
        self::$_exemptedIp = $exemptedIp;
    }

    /**
     * set configuration options of the traffic limiter
     *
     * @access public
     * @static
     * @param Configuration $conf
     */
    public static function setConfiguration(Configuration $conf)
    {
        self::setLimit($conf->getKey('limit', 'traffic'));
        self::setPath($conf->getKey('dir', 'traffic'));
        self::setExemptedIp($conf->getKey('exemptedIp', 'traffic'));

        if (($option = $conf->getKey('header', 'traffic')) !== null) {
            $httpHeader = 'HTTP_' . $option;
            if (array_key_exists($httpHeader, $_SERVER) && !empty($_SERVER[$httpHeader])) {
                self::$_ipKey = $httpHeader;
            }
        }
    }

    /**
     * get a HMAC of the current visitors IP address
     *
     * @access public
     * @static
     * @param  string $algo
     * @return string
     */
    public static function getHash($algo = 'sha512')
    {
        return hash_hmac($algo, $_SERVER[self::$_ipKey], ServerSalt::get());
    }

    /**
     * Validate $_ipKey against configured ipranges. If matched we will ignore the ip
     *
     * @access private
     * @static
     * @param  string $ipRange
     * @return bool
     */
    private static function matchIp($ipRange = null)
    {
        if (is_string($ipRange)) {
            $ipRange = trim($ipRange);
        }
        $address = Factory::addressFromString($_SERVER[self::$_ipKey]);
        $range   = Factory::rangeFromString($ipRange);

        // address could not be parsed, we might not be in IP space and try a string comparison instead
        if (is_null($address)) {
            return $_SERVER[self::$_ipKey] === $ipRange;
        }
        // range could not be parsed, possibly an invalid ip range given in config
        if (is_null($range)) {
            return false;
        }

        // Ip-lib throws an exception when something goes wrong, if so we want to catch it and set contained to false
        try {
            return $address->matches($range);
        } catch (Exception $e) {
            // If something is wrong with matching the ip, we assume it doesn't match
            return false;
        }
    }

    /**
     * traffic limiter
     *
     * Make sure the IP address makes at most 1 request every 10 seconds.
     *
     * @access public
     * @static
     * @throws Exception
     * @return bool
     */
    public static function canPass()
    {
        // disable limits if set to less then 1
        if (self::$_limit < 1) {
            return true;
        }

        // Check if $_ipKey is exempted from ratelimiting
        if (!is_null(self::$_exemptedIp)) {
            $exIp_array = explode(',', self::$_exemptedIp);
            foreach ($exIp_array as $ipRange) {
                if (self::matchIp($ipRange) === true) {
                    return true;
                }
            }
        }

        $file = 'traffic_limiter.php';
        if (self::_exists($file)) {
            require self::getPath($file);
            $tl = $GLOBALS['traffic_limiter'];
        } else {
            $tl = array();
        }

        // purge file of expired hashes to keep it small
        $now = time();
        foreach ($tl as $key => $time) {
            if ($time + self::$_limit < $now) {
                unset($tl[$key]);
            }
        }

        // this hash is used as an array key, hence a shorter algo is used
        $hash = self::getHash('sha256');
        if (array_key_exists($hash, $tl) && ($tl[$hash] + self::$_limit >= $now)) {
            $result = false;
        } else {
            $tl[$hash] = time();
            $result    = true;
        }
        self::_store(
            $file,
            '<?php' . PHP_EOL .
            '$GLOBALS[\'traffic_limiter\'] = ' . var_export($tl, true) . ';'
        );
        return $result;
    }
}
