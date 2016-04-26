<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_editor extends DokuWiki_Plugin {

    function getMethods() {
        $result = array();
        $result[] = array(
                'name'   => 'getEditor',
                'desc'   => 'returns pages recently edited by a given user',
                'params' => array(
                    'namespace (optional)' => 'string',
                    'number (optional)' => 'integer',
                    'user (required)' => 'string'),
                'return' => array('pages' => 'array'),
                );
        return $result;
    }

    /**
     * Get pages edited by user from a given namespace
     */
    function getEditor($ns = '', $num = NULL, $user = '') {
        global $conf;

        if (!$user) $user = $_REQUEST['user'];

        $first  = $_REQUEST['first'];
        if (!is_numeric($first)) $first = 0;

        if ((!$num) || (!is_numeric($num))) $num = $conf['recent'];

        if ($user == '@ALL') {                                                 // all users
            $type = 'all';
        } elseif ($user{0} == '@') {                                           // filter group
            global $auth;

            if (($auth) && ($auth->canDo('getUsers'))) {
                $user = $auth->retrieveUsers(0, 0, array('grps' => substr($user, 1)));
                $user = array_keys($user);
                $type = 'group';
            } else {
                msg('Group filtering not supported by authentification class.', -1);
                return array();
            }
        } elseif (preg_match("/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/", $user)) { // filter IP
            $type = 'ip';
        } else {                                                              // filter user
            $type = 'user';
        }

        // read all recent changes. (kept short)
        $lines = file($conf['changelog']);

        // handle lines
        $result = array();
        $count  = 0;
        for ($i = count($lines)-1; $i >= 0; $i--) {
            $rec = $this->_handleRecent($lines[$i], $ns, $type, $user);
            if ($rec !== false) {
                if (--$first >= 0) continue; // skip first entries
                $result[] = $rec;
                $count++;
                // break when we have enough entries
                if ($count >= $num) break;
            }
        }

        // clear static $seen in _handleRecent
        $this->_handleRecent(array(), '', 'clear', '');

        return $result;
    }

    /* ---------- Changelog function adapted for the Editor Plugin ---------- */

    /**
     * Internal function used by $this->getPages()
     *
     * don't call directly
     *
     * @see getRecents()
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Ben Coburn <btcoburn@silicodon.net>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    function _handleRecent($line, $ns, $type, $user) {
        global $auth;                          // authentification class
        static $seen = array();                // caches seen pages and skip them

        if ($type == 'clear') $seen = array(); // clear seen pages cache
        if (empty($line)) return false;        // skip empty lines

        // split the line into parts
        $recent = parseChangelogLine($line);
        if ($recent === false) return false;

        // skip seen ones
        if(isset($seen[$recent['id']])) return false;

        // entry clauses for user and ip filtering
        switch ($type) {
            case 'all':
                break;
            case 'user':
                if ($recent['user'] != $user) return false;
                break;
            case 'group':
                if (!in_array($recent['user'], $user)) return false;
                break;
            case 'ip':
                if ($recent['ip'] != $user) return false;
                break;
        }

        // skip minors
        if ($recent['type']==='e') return false;

        // remember in seen to skip additional sights
        $seen[$recent['id']] = 1;

        // check if it's a hidden page
        if (isHiddenPage($recent['id'])) return false;

        // filter namespace
        if (($ns) && (strpos($recent['id'], $ns.':') !== 0)) return false;

        // check ACL
        $recent['perm'] = auth_quickaclcheck($recent['id']);
        if ($recent['perm'] < AUTH_READ) return false;

        // check existance
        $recent['file']   = wikiFN($recent['id']);
        $recent['exists'] = @file_exists($recent['file']);
        if (!$recent['exists']) return false;

        $recent['desc']   = $recent['sum'];
        if ($recent['user']) {
            $userinfo = $auth->getUserData($recent['user']);
            if ($userinfo) $recent['user'] = $userinfo['name'];
        } else {
            $recent['user'] = $recent['ip'];
        }

        return $recent;
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
