// GPL-2.0-only. All data and resources in this test are synthetic and local.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const models=['graphify','architecture','hostile'].map(name=>JSON.parse(fs.readFileSync(path.join(root,'tests/output',name+'.model.json'))));
models[0].hyperedges=[{nodes:['guide','service','database'],label:'Example subsystem'}];
function safeJSON(value) { return JSON.stringify(value).replaceAll('<','\\u003c').replaceAll('>','\\u003e'); }
const server=createServer((req,res)=>{
    const files={'/vendor.js':'vendor/vis-network.min.js','/script.js':'script.js','/style.css':'style.css'};
    if(files[req.url]) { res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'text/javascript'); res.end(fs.readFileSync(path.join(root,files[req.url])));return; }
    res.setHeader('Content-Type','text/html; charset=utf-8');
    res.end('<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/style.css"><style>body{margin:24px}main{max-width:1120px;margin:auto}</style><main>'+
        models.map(m=>'<div class="graphify-widget"><script type="application/json" class="graphify-data">'+safeJSON(m)+'</script><p class="graphify-loading">Loading</p></div>').join('')+
        '</main><script src="/vendor.js"></script><script src="/script.js"></script>');
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({executablePath:process.env.GRAPHIFY_CHROME_EXECUTABLE || undefined,headless:true,chromiumSandbox:true});
try {
    const page=await browser.newPage({viewport:{width:1440,height:1000}}), urls=[];
    page.on('request',req=>urls.push(req.url()));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.waitForFunction(()=>window.GraphifyWiki?.instances.length===3);
    assert.equal(await page.locator('iframe,img').count(),0,'No iframe or hostile HTML image');
    const width=await page.evaluate(()=>{const r=document.querySelector('.graphify-widget');return {root:r.clientWidth,canvas:r.querySelector('.graphify-canvas').clientWidth};});
    assert.ok(width.canvas>=width.root-3,'Full initial width');
    const first=page.locator('.graphify-widget').first();
    await first.locator('input[type=search]').fill('database');
    assert.equal(await first.locator('[data-action=result]').count(),1,'Search');
    await first.locator('[data-action=result]').click();
    assert.ok((await first.locator('.graphify-detail').textContent()).includes('Example database'),'Details');
    const before=await page.evaluate(()=>GraphifyWiki.instances[0].network.getScale());
    await first.locator('[data-action=zoom-in]').click();
    assert.ok(await page.evaluate(old=>GraphifyWiki.instances[0].network.getScale()>old,before),'Zoom');
    await first.locator('[data-action=details]').click();
    await first.locator('[data-action=group]').first().uncheck();
    assert.equal(await page.evaluate(()=>GraphifyWiki.instances[0].nodes.get({filter:n=>n.hidden}).length),2,'Community filter');
    await first.locator('[data-action=group]').first().check();
    await first.locator('[data-action=confidence]').first().uncheck();
    assert.equal(await page.evaluate(()=>GraphifyWiki.instances[0].edges.get({filter:e=>e.hidden}).length),2,'Confidence filter');
    await first.locator('[data-action=confidence]').first().check();
    await page.evaluate(()=>GraphifyWiki.instances[0].trace('guide','database'));
    assert.ok((await first.locator('.graphify-status').textContent()).includes('Example service → Example database'),'Directed path');
    await first.locator('[data-action=theme]').click();
    assert.ok((await first.getAttribute('class')).includes('graphify-dark'),'Theme');
    await first.locator('[data-action=fullscreen]').click();
    await page.waitForFunction(()=>!!document.fullscreenElement);
    await page.evaluate(()=>document.exitFullscreen());
    const download=page.waitForEvent('download'); await first.locator('[data-action=export]').click();
    const file=await (await download).path();
    assert.ok(fs.readFileSync(file).subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])),'Valid PNG');
    await page.setViewportSize({width:600,height:1000});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Responsive width');
    assert.ok(urls.every(url=>url.startsWith(`http://127.0.0.1:${server.address().port}/`) || url.startsWith('blob:') || url.startsWith('data:')),'No external requests');
    console.log(JSON.stringify({instances:3,fullWidth:width,search:true,filters:true,zoom:true,path:true,theme:true,fullscreen:true,png:true,responsive:true}));
} finally { await browser.close(); server.close(); }
