<?php
/** Synthetic PDF output/security tests, independent of a live Wiki or Kroki. */
namespace dokuwiki\Extension {
    class Plugin { public $settings=[]; public function getConf($key) { return $this->settings[$key] ?? ''; } }
    class SyntaxPlugin extends Plugin {} class ActionPlugin extends Plugin {}
    class Event { public $data; public function __construct($data) { $this->data=$data; } }
    class EventHandler { public $hook; public function register_hook(...$args) { $this->hook=$args; } }
}
namespace {
define('DOKU_INC', __DIR__ . '/'); define('AUTH_READ', 1);
class Doku_Handler {}
class Doku_Renderer { public $doc=''; public $info=[]; public $meta=[]; }
class renderer_plugin_dw2pdf extends Doku_Renderer {}
class TestInput { public $values=[]; public function set($key,$value) { $this->values[$key]=$value; } }
function hsc($text) { return htmlspecialchars($text,ENT_QUOTES,'UTF-8'); }
function io_mkdir_p($dir) { if (!is_dir($dir)) mkdir($dir,0700,true); }
function io_saveFile($file,$data) { return file_put_contents($file,$data); }
function getNS($id) { return substr($id,0,strrpos($id,':')); }
function mediaFN($id) { return __DIR__.'/fixtures/graphify.json'; }
$acl=1;
function auth_quickaclcheck($id) { global $acl; return $acl; }
require dirname(__DIR__).'/helper.php'; require dirname(__DIR__).'/pdf.php';
require dirname(__DIR__).'/syntax.php'; require dirname(__DIR__).'/action.php';
$helper=new helper_plugin_graphify;
function plugin_load($type,$name) { global $helper; return $helper; }
class FakePdf extends GraphifyPdf {
    public $response=false; public $calls=0;
    protected function fetch($url,$dot) { $this->calls++; return $this->response; }
}
$count=0;
function check($ok,$message) { global $count; if (!$ok) throw new RuntimeException($message); $count++; }
function reject($fn,$message) { try { $fn(); } catch (Throwable $e) { check(true,$message); return; } check(false,$message); }
$plugin=new \dokuwiki\Extension\Plugin;
$plugin->settings=['pdf_backend_url'=>'http://renderer.invalid:8000','pdf_timeout'=>30];
$pdf=new FakePdf($plugin);
$graph=file_get_contents(__DIR__.'/fixtures/graphify.json'); $model=$helper->model($graph);
$dot=$pdf->dot($model);
check(str_contains($dot,'digraph') && str_contains($dot,'n0 -> n1'),'Directed export');
check(str_contains($dot,'[EXTRACTED]') && str_contains($dot,'dashed') && str_contains($dot,'dotted'),'Confidence styles');
$model['directed']=false; check(str_contains($pdf->dot($model),'n0 -- n1'),'Undirected export');
$model['nodes'][0]['label']='" ]; image="https://example.invalid/x"; \\N';
check(str_contains($pdf->dot($model),'image=\\"https://example.invalid/x\\";') && str_contains($pdf->dot($model),'\\\\N'),'DOT label escapes cannot become attributes');
$model['hyperedges']=[['nodes'=>['guide','service'],'label'=>'Shared context']];
check(str_contains($pdf->dot($model),'h0 -- n0') && str_contains($pdf->dot($model),'Hyperedge: Shared context'),'Hyperedge membership');
$arch=$helper->model(file_get_contents(__DIR__.'/fixtures/architecture.json'));
$dot=$pdf->dot($arch);
check(str_contains($dot,'layout=neato') && str_contains($dot,'pin=true') && str_contains($dot,'pos="1.52778,-1.52778!"'),'Architecture geometry');
check(str_contains($dot,'Browser') && str_contains($dot,'Example') && !str_contains($dot,'EXTRACTED'),'Architecture meaning');
$conf=['cachedir'=>__DIR__.'/output/pdf-cache-'.getmypid()];
$pdf->response=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$html=$pdf->html($arch);
check(str_contains($html,'data:image/png;base64,') && !str_contains($html,'<script'),'Static PNG');
check(str_contains($html,'Synthetic example') && str_contains($html,'All names and components are invented.'),'Searchable cards');
$pdf->html($arch); check($pdf->calls===1,'Private source/backend image cache');
$plugin->settings['pdf_backend_url']='http://other.invalid:8000'; $pdf->response='<html>error</html>';
reject(fn()=>$pdf->html($arch),'Invalid PNG and backend cache separation');
foreach (['','file:///tmp/render','http://user:pass@renderer.invalid','https://renderer.invalid/?x=y'] as $url) {
    $plugin->settings['pdf_backend_url']=$url; reject(fn()=>$pdf->html($arch),'Explicit trusted backend only');
}
$syntax=new syntax_plugin_graphify;
$renderer=new renderer_plugin_dw2pdf; $syntax->render('xhtml',$renderer,['inline',$graph]);
check(str_contains($renderer->doc,'PDF rendering requires') && !str_contains($renderer->doc,'graphify-widget'),'dw2pdf renderer detected despite xhtml mode');
$renderer=new Doku_Renderer; $syntax->render('metadata',$renderer,['media','example:graph.json']);
check($renderer->meta['relation']['media']['example:graph.json']===true,'Media dependency');
$acl=0; $renderer=new renderer_plugin_dw2pdf;
$syntax->render('xhtml',$renderer,['media','example:graph.json']);
check(str_contains($renderer->doc,'JSON media unavailable') && !str_contains($renderer->doc,'data:image/png'),'ACL checked before PDF/cache');
$action=new action_plugin_graphify; $controller=new \dokuwiki\Extension\EventHandler;
$action->register($controller); check($controller->hook[5]<0,'PDF cache hook runs before exporter');
$INPUT=new TestInput; $action->freshPdf(new \dokuwiki\Extension\Event('show'),null);
check(!$INPUT->values,'Normal page untouched');
foreach (['export_pdf','export_pdfbook','export_pdfns'] as $event) {
    $INPUT=new TestInput; $action->freshPdf(new \dokuwiki\Extension\Event($event),null);
    check($INPUT->values['purge']===true,'Fresh document checks for '.$event);
}
foreach (glob($conf['cachedir'].'/graphify-pdf/*.png') ?: [] as $file) unlink($file);
if (is_dir($conf['cachedir'].'/graphify-pdf')) rmdir($conf['cachedir'].'/graphify-pdf');
if (is_dir($conf['cachedir'])) rmdir($conf['cachedir']);
echo "$count PDF, fidelity, backend and ACL checks passed.\n";
}
