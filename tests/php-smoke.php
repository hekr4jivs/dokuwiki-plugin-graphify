<?php
/** Validate schemas and security in a staged directory without Wiki credentials. */
namespace dokuwiki\Extension { class Plugin {} class SyntaxPlugin {} }
namespace {
define('DOKU_INC', __DIR__ . '/'); define('AUTH_READ', 1);
class Doku_Handler {}
class Doku_Renderer { public $doc = ''; public $info = []; }
$acl = 1; $checked = ''; $mediafile = __DIR__ . '/fixtures/graphify.json';
function auth_quickaclcheck($ns) { global $acl,$checked; $checked=$ns; return $acl; }
function getNS($id) { return substr($id,0,strrpos($id,':')); }
function mediaFN($id) { global $mediafile; return $mediafile; }
function hsc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/helper.php'; require dirname(__DIR__) . '/syntax.php';
$helper = new \helper_plugin_graphify;
function plugin_load($type,$name) { global $helper; return $helper; }
if (!is_dir(__DIR__ . '/output')) mkdir(__DIR__ . '/output');
$count=0;
function check($test,$message) { global $count; if (!$test) throw new \RuntimeException($message); $count++; }
function reject($fn,$message) { try { $fn(); } catch (\Throwable $error) { check(true,$message); return; } check(false,$message); }
$graph = file_get_contents(__DIR__ . '/fixtures/graphify.json');
$raw = json_decode($graph,true); $model=$helper->model($graph);
check(count($model['nodes']) === 4 && count($model['edges']) === 4, 'Graphify node-link JSON');
$raw['edges']=$raw['links']; unset($raw['links']);
check(count($helper->model(json_encode($raw))['edges']) === 4, 'Extraction nodes/edges');
$raw['nodes'][]=$raw['nodes'][0]; reject(fn() => $helper->model(json_encode($raw)), 'Duplicate nodes');
$raw=json_decode($graph,true); $raw['links'][0]['target']='missing'; reject(fn() => $helper->model(json_encode($raw)), 'Dangling edge');
reject(fn() => $helper->model('{bad'), 'Malformed JSON');
$raw=json_decode($graph,true); $raw['directed']='false'; reject(fn() => $helper->model(json_encode($raw)), 'Nonboolean direction');
$raw=json_decode($graph,true); $raw['hyperedges']=[['nodes'=>['guide','service'],'label'=>'group']];
check(count($helper->model(json_encode($raw))['hyperedges']) === 1,'Hyperedge members');
$raw['hyperedges'][0]['nodes'][]='unknown'; reject(fn() => $helper->model(json_encode($raw)), 'Dangling hyperedge');
$arch=$helper->model(file_get_contents(__DIR__ . '/fixtures/architecture.json'));
check($arch['format']==='archify' && count($arch['nodes'])===3 && count($arch['cards'])===1 && count($arch['edges'])===2, 'Synthetic architecture');
$raw=json_decode(file_get_contents(__DIR__ . '/fixtures/architecture.json'),true); $raw['boundaries']=[['id'=>'boundary']];
reject(fn() => $helper->model(json_encode($raw)), 'Unsupported boundaries explicitly rejected');
$syntax = new \syntax_plugin_graphify;
$data=$syntax->handle("<graphify>\n$graph\n</graphify>",0,0,new \Doku_Handler);
$renderer=new \Doku_Renderer; $syntax->render('xhtml',$renderer,$data);
check(str_contains($renderer->doc,'application/json') && !str_contains($renderer->doc,'iframe'),'Native inline data renderer');
check($renderer->info['cache'] === false, 'ACL-dependent output never cached');
$raw=json_decode($graph,true); $raw['nodes'][0]['label']='</script><img src=x onerror=alert(1)>';
$renderer=new \Doku_Renderer; $syntax->render('xhtml',$renderer,['inline',json_encode($raw)]);
check(!str_contains($renderer->doc,'<img') && str_contains($renderer->doc,'\\u003C'), 'Script break-out escaped');
$helper->media('intern:example:graph.json'); check($checked === 'intern:example:*','Exact media ACL namespace');
$acl=0; reject(fn() => $helper->media('intern:example:graph.json'), 'Denied media'); $acl=1;
reject(fn() => $helper->media('../graph.json'), 'Traversal');
$media=$syntax->handle('{{graphify>intern:example:graph.json}}',0,0,new \Doku_Handler);
check($media === ['media','intern:example:graph.json'],'Media syntax');
preg_match('~<script[^>]*>(.*?)</script>~s',$renderer->doc,$match);
file_put_contents(__DIR__ . '/output/graphify.model.json',json_encode($model));
file_put_contents(__DIR__ . '/output/architecture.model.json',json_encode($arch));
file_put_contents(__DIR__ . '/output/hostile.model.json',$match[1]);
echo "$count schema, ACL and encoding checks passed.\n";
}
