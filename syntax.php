<?php
/**
 * Editor Plugin: displays links to all wiki pages edited by a given user or ip
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_editor extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-11-22',
      'name'   => 'Editor Plugin',
      'desc'   => 'Displays a list of recently changed wiki pages by a given author',
      'url'    => 'http://www.wikidesign.ch/en/plugin/editor/',
    );
  }

  function getType(){ return 'substition'; }
  function getPType(){ return 'block'; }
  function getSort(){ return 309; }
  function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{editor>.+?\}\}',$mode,'plugin_editor'); }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match, 9, -2); // strip {{editor> from start and }} from end
    list($ns, $rest) = explode("?", $match);
    if (!$rest){
      $rest = $ns;
      $ns   = '';
    }
    
    if (preg_match("/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/", $rest)) $type = 'ip';
    else $type = 'user';    
    
    return array($type, $ns, $rest);
  }

  /**
   * Create output
   */
  function render($mode, &$renderer, $data) {
    global $ID;
    global $conf;
    global $auth;
    
    $ns   = $data[1];
    $user = $data[2];
    
    $pages = $this->_editorArchive($ns, $user, $data[0]);
    
    if (!count($pages)) return true; // nothing to display
    
    if ($mode == 'xhtml'){

      // prevent caching to ensure content is always fresh
      $renderer->info['cache'] = false;
        
      $renderer->doc .= '<table class="editor">';
      foreach ($pages as $page){
        $renderer->doc .= '<tr><td class="page">';
        $id  = $page['id'];
        $title = p_get_first_heading($id);
        $renderer->doc .= $renderer->internallink(':'.$id, $title).'</td>';
        if ($this->getConf('showdate')){
          $renderer->doc .= '<td class="date">'.date($conf['dformat'],$page['date']).'</td>';
        }
        if (($user == '@ALL') && $page['user'] && $this->getConf('showuser') && !is_null($auth)){
          $userInfo = $auth->getUserData($page['user']);
          $renderer->doc .= '<td class="user">'.$userInfo['name'].'</td>';
        }
        $renderer->doc .= '</tr>';
      }
      $renderer->doc .= '</table>';
      
      return true;
      
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      foreach ($pages as $page){
        $id  = $page['id'];
        $renderer->meta['relation']['references'][$id] = true;
        // $meta = array('relation' => array('isreferencedby' => array($ID => true)));
        // p_set_metadata($id, $meta);
      }
      
      return true;
    }
    return false;
  }
  
  /**
   * return the editor archive list
   */
  function _editorArchive($ns, $user, $type){
    global $conf;
    
    $result = array();
    $num    = $conf['recent'];
    $flags  = RECENTS_SKIP_DELETED + RECENTS_SKIP_MINORS;
    if ($ns == '*') $ns = '';
    $first  = $_REQUEST['first'];
    if (!is_numeric($first)) $first = 0;
        
    $result = $this->_getRecents($first, $num, $ns, $flags, $type, $user);
    
    if ((count($result) == 0) && $first)
      $result = $this->_getRecents(0, $num, $ns, $flags, $type, $user);
            
    return $result;
  }
  
/* ---------- Changelog functions adapted for the Editor Plugin ---------- */
  
  /**
   * returns an array of recently changed files using the
   * changelog
   *
   * The following constants can be used to control which changes are
   * included. Add them together as needed.
   *
   * RECENTS_SKIP_DELETED   - don't include deleted pages
   * RECENTS_SKIP_MINORS    - don't include minor changes
   * RECENTS_SKIP_SUBSPACES - don't include subspaces
   *
   * @param int    $first   number of first entry returned (for paginating
   * @param int    $num     return $num entries
   * @param string $ns      restrict to given namespace
   * @param bool   $flags   see above
   *
   * @author Ben Coburn <btcoburn@silicodon.net>
   */
  function _getRecents($first, $num, $ns='', $flags=0, $type='user', $user='@ALL'){
    global $conf;
    $recent = array();
    $count  = 0;
  
    if(!$num)
      return $recent;
  
    // read all recent changes. (kept short)
    $lines = file($conf['changelog']);
  
  
    // handle lines
    for($i = count($lines)-1; $i >= 0; $i--){
      $rec = $this->_handleRecent($lines[$i], $ns, $flags, $type, $user);
      if($rec !== false) {
        if(--$first >= 0) continue; // skip first entries
        $recent[] = $rec;
        $count++;
        // break when we have enough entries
        if($count >= $num){ break; }
      }
    }
  
    return $recent;
  }
  
  /**
   * Internal function used by getRecents
   *
   * don't call directly
   *
   * @see getRecents()
   * @author Andreas Gohr <andi@splitbrain.org>
   * @author Ben Coburn <btcoburn@silicodon.net>
   */
  function _handleRecent($line, $ns, $flags, $type, $data){
    static $seen  = array();         //caches seen pages and skip them
    if(empty($line)) return false;   //skip empty lines
  
    // split the line into parts
    $recent = parseChangelogLine($line);
    if ($recent===false) { return false; }
  
    // skip seen ones
    if(isset($seen[$recent['id']])) return false;
    
    // entry clauses for user and ip filtering
    switch ($type){
      case 'user':
        if (($recent['user'] != $data) && ($data != '@ALL')) return false;
        else break;
      case 'ip':
        if ($recent['ip'] != $data) return false;
        else break;
    }
  
    // skip minors
    if($recent['type']==='e' && ($flags & RECENTS_SKIP_MINORS)) return false;
  
    // remember in seen to skip additional sights
    $seen[$recent['id']] = 1;
  
    // check if it's a hidden page
    if(isHiddenPage($recent['id'])) return false;
  
    // filter namespace
    if (($ns) && (strpos($recent['id'],$ns.':') !== 0)) return false;
  
    // exclude subnamespaces
    if (($flags & RECENTS_SKIP_SUBSPACES) && (getNS($recent['id']) != $ns)) return false;
  
    // check ACL
    if (auth_quickaclcheck($recent['id']) < AUTH_READ) return false;
  
    // check existance
    if((!@file_exists(wikiFN($recent['id']))) && ($flags & RECENTS_SKIP_DELETED)) return false;
  
    return $recent;
  }
        
}

//Setup VIM: ex: et ts=4 enc=utf-8 :