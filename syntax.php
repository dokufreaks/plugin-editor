<?php
/**
 * Editor Plugin: displays links to all wiki pages edited by a given user or ip
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_editor extends DokuWiki_Syntax_Plugin {

  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2007-01-11',
      'name'   => 'Editor Plugin',
      'desc'   => 'Displays a list of recently changed wiki pages by a given author',
      'url'    => 'http://www.wikidesign.ch/en/plugin/editor/start',
    );
  }

  function getType(){ return 'substition'; }
  function getPType(){ return 'block'; }
  function getSort(){ return 309; }
  
  function connectTo($mode){
    $this->Lexer->addSpecialPattern('\{\{editor>.+?\}\}',$mode,'plugin_editor');
  }

  function handle($match, $state, $pos, &$handler){
    global $ID;
    
    $match = substr($match, 9, -2); // strip {{editor> from start and }} from end
    list($ns, $user) = explode('?', $match);
    
    if (!$user){
      $user = $ns;
      $ns   = '';
    }
    
    if (($ns == '*') || ($ns == ':')) $ns = '';
    elseif ($ns == '.') $ns = getNS($ID);
    else $ns = cleanID($ns);
    
    return array($ns, trim($user));
  }

  function render($mode, &$renderer, $data) {
    list($ns, $user) = $data;
        
    if ($my =& plugin_load('helper', 'editor')) $pages = $my->getEditor($ns, '', $user);
    if (!$pages) return true; // nothing to display
    
    if ($mode == 'xhtml'){

      // prevent caching to ensure content is always fresh
      $renderer->info['cache'] = false;
      
      // let Pagelist Plugin do the work for us
      if (plugin_isdisabled('include')
        || (!$pagelist =& plugin_load('helper', 'pagelist'))){
        msg('The Pagelist Plugin must be installed for editor lists to work.', -1);
        return false;
      }
      
      // hide user column, unless for groups
      if ($user{0} != '@') $pagelist->column['user'] = false;
            
      $pagelist->startList();
      foreach ($pages as $page){        
        $pagelist->addPage($page);
      }
      $renderer->doc .= $pagelist->finishList();      
      return true;
            
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      foreach ($pages as $page){
        $renderer->meta['relation']['references'][$page['id']] = true;
      }
      
      return true;
    }
    return false;
  }
  
}