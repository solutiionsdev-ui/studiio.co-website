// Turns STUDIIO_BEATS (the reel's beats.json) into the overlay: one big word and one effect per beat.
// Runs inside the Reels Montage PRO overlay template, at the end of its EDIT PER VIDEO block, and
// replaces plates()/backPlates() with renderers driven by the beats. The effect names match
// REEL_EFFECTS in admin/lib/reels.php. The live preview in the admin calls STUDIIO_APPLY(doc).
;(function(){
 window.STUDIIO_AUTO={big:BIG.map(r=>r.slice()),kinds:PH.map(p=>p.kind),raw:PH.map(p=>p.raw)};
 const SAFE=s=>String(s==null?'':s).replace(/</g,'‹').replace(/>/g,'›').replace(/"/g,'”').replace(/['`]/g,'’');
 const STOP=/^(the|and|did|not|but|you|was|are|for|with|this|that|its|it's|cup|all|just|one|what|how|has|had|can|our|your|will|need|don't|dont|have|make|made|get|got|there|they|them|then|than|from|into|about|really|very)$/;
 const keyWords=(a,b)=>W.filter(w=>w[0]>=a&&w[0]<b).map(w=>w[2].replace(/[.,?!]/g,'').toLowerCase()).filter(w=>w.length>2&&!STOP.test(w)).filter((w,k,arr)=>arr.indexOf(w)===k);
 const NOBIG=new Set(['title','bracketWord','ghostWord','noiseWord','focusWord','ribbon','ctaWord','typeLine','behindWord','walls','wordSwarm','titleWall','split','uiWall','duoCards','collage','shutter','carouselWide','flowNodes','phaseCards','halfBlur','mapRoute']);
 const num=(s,d)=>{const m=String(s||'').match(/-?\d[\d.,]*/);return m?parseFloat(m[0].replace(/,/g,'')):d};
 const list=(c,fb)=>(c.items.length?c.items:fb).filter(Boolean);
 const pair=s=>{const m=String(s).match(/^(.*?)\s*[:—–]\s*(.+)$/);return m?[m[1].trim(),m[2].trim()]:[String(s),'']};
 const lead=s=>{const m=String(s).match(/^(\S+)\s+(.+)$/);return m?[m[1],m[2]]:[String(s),'']};
 const frost=(c,inner)=>BANK.scene(c.t,c.A,c.B,inner,{kind:'frost'});
 const CTA={word:'',gift:'FREE GUIDE'};
 const orb=(c,part)=>({cx:c.H.cx,cy:Math.min(1400,c.FB+120),rx:470,ry:150,size:210,part});
 const ringWords=c=>list(c,c.ws.slice(0,8)).slice(0,8).map((w,k)=>[w,c.A+.1*(k+1)]);
 const splitInner=c=>`<div style="padding:120px 90px;color:#1d1d1f;font-family:Inter"><div style="font:500 70px Inter;letter-spacing:-.02em">${ty(c.say||c.big.toLowerCase(),c.t,c.A+.3,18)}</div><div style="font:400 36px Inter;color:#444;margin-top:30px;line-height:1.7">${c.items.slice(0,4).map((s,i)=>`<div style="opacity:${cl((c.t-c.A-1.1-i*.3)/.2)}"><span style="color:#F2632A">●</span> ${s}</div>`).join('')}</div></div>`;
 // f: front layer (in plates), b: back layer (behind the person), g: an under-the-chin plate (skipped on close-ups).
 // c.color is the accent colour when the beat's accent box is ticked (for word effects that take a colour).
 const R={
  askBar:{g:1,f:c=>BANK.askBar(c.t,c.A,c.B,c.say||c.txt||c.big,{y:c.y,typeAt:c.A+.1})},
  allowBar:{g:1,f:c=>BANK.allowBar(c.t,c.A,c.B,c.say||c.txt||c.big,{y:c.fy(c.y,1180),pressAt:c.A+(c.B-c.A)*.6})},
  notifyStack:{g:1,f:c=>BANK.notifyStack(c.t,c.A,c.B,c.items.length?c.items.slice(0,4).map((m,k)=>[m,c.A+.1+k*Math.min(.6,(c.B-c.A)/(Math.min(4,c.items.length)+1))]):[[c.txt||c.big,c.A+.1],[c.big,c.A+(c.B-c.A)*.5]],{y:c.fy(c.y,1060),app:'MESSAGES'})},
  toggleList:{g:1,f:c=>BANK.toggleList(c.t,c.A,c.B,list(c,[c.w0,c.w1,c.w2]).slice(0,5).map((w,k)=>[w,c.A+.35*(k+1)]),{y:c.fy(c.y,1080)})},
  checklist:{g:1,f:c=>BANK.checklist(c.t,c.A,c.B,list(c,[c.w0,c.w1,c.w2]).slice(0,5).map((w,k)=>[w,c.A+.4*(k+1)]),{y:c.fy(c.y,1070),rowH:84,gap:12})},
  statCard:{g:1,f:c=>BANK.statCard(c.t,c.A,c.B,list(c,[c.w0,c.w1,c.w2]).slice(0,5).map((w,k)=>[w,c.A+.3*k]),{y:c.fy(c.y,1080),label:c.say||c.big})},
  statLines:{g:1,f:c=>BANK.statLines(c.t,c.A,c.B,c.items.length?c.items.slice(0,3).map(lead):[['01',c.w0],['02',c.w1]],{y:c.fy(c.y,1040),title:c.say||c.big})},
  numPair:{g:1,f:c=>{let a,b;if(c.items.length>=2){a=pair(c.items[0]);b=pair(c.items[1])}else{const m=c.raw.match(/\$?\d[\d.,]*%?/g)||[];a=[c.w0,m[0]||'0'];b=[c.big,m[1]||m[0]||'100%']}return BANK.numPair(c.t,c.A,c.B,a,b,c.A+(c.B-c.A)*.45,{y:c.fy(c.y,1060)})}},
  sliderBar:{g:1,f:c=>BANK.sliderBar(c.t,c.A,c.B,{y:c.fy(c.y+40,1240),label:c.say||c.big,to:num(c.num,100),unit:!c.num||/%/.test(c.num)?'%':''})},
  scaleBar:{g:1,f:c=>{const v=num(c.num,NaN),m=(c.raw.match(/\d+/)||[])[0];return BANK.scaleBar(c.t,c.A,c.B,{y:c.fy(c.y,1080),value:!isNaN(v)&&v<=100?v:(m&&+m<=100?+m:88),label:c.say||c.big})}},
  stopwatch:{g:1,f:c=>BANK.stopwatch(c.t,c.A,c.B,{to:num(c.num,Math.max(3,Math.round(c.B-c.A))),label:(c.say||c.big).toUpperCase(),y:c.fy(c.y+30,1200),size:130,glass:true})},
  segmented:{g:1,f:c=>{const o=list(c,[c.w0,c.w1,c.big]).slice(0,3).map(s=>s.slice(0,12));return BANK.segmented(c.t,c.A,c.B,o,o.map((_,k)=>c.A+.5*k),{y:c.fy(c.y,1060)})}},
  duoCircles:{g:1,f:c=>{const it=c.items.length>=2?c.items.map(pair):[['before',c.w0],['after',c.big]];return BANK.duoCircles(c.t,c.A,c.B,it[0],it[1],{cy:c.fy(c.y+220,1250),r:190})}},
  liquidChart:{g:1,f:c=>BANK.liquidChart(c.t,c.A,c.B,{y:c.fy(c.y+20,1050),h:420,label:(c.say||c.big).toUpperCase(),to:num(c.num,37)})},
  chartCard:{g:1,f:c=>BANK.chartCard(c.t,c.A,c.B,[.2,.35,.3,.55,.5,.8,.95],{y:c.fy(c.y-40,980),title:c.say||'Monthly Report'})},
  scoreCard:{g:1,f:c=>BANK.scoreCard(c.t,c.A,c.B,{y:c.fy(c.y,1000),title:c.say||'Score',value:num(c.num,88)})},
  layers:{g:1,f:c=>BANK.layers(c.t,c.A,c.B,list(c,c.ws.slice(0,4)).slice(0,5).map((w,k)=>[w,k%2]),{cx:540,cy:c.fy(c.y+260,1250)})},
  circles:{g:1,f:c=>BANK.circles(c.t,c.A,c.B,list(c,c.ws.slice(0,5)).slice(0,5).map((w,k)=>[w,c.A+.2*(k+1)]),{cy:c.fy(c.y+250,1250)})},
  timeline:{g:1,f:c=>BANK.timeline(c.t,c.A,c.B,list(c,c.ws.slice(0,5)).slice(0,5).map((w,k)=>{const p=pair(w);return p[1]?[p[0].toUpperCase(),p[1],c.A+.2*(k+1)]:['0'+(k+1),w,c.A+.2*(k+1)]}),{y0:Math.min(1400,c.FB+420)})},
  cardStack3D:{g:1,f:c=>BANK.cardStack3D(c.t,c.A,c.B,list(c,c.ws.slice(0,3)).slice(0,4).map((w,k)=>['0'+(k+1),w]),{y:c.fy(c.y+120,1200)})},
  routeSteps:{g:1,f:c=>BANK.routeSteps(c.t,c.A,c.B,list(c,c.ws.slice(0,3)).slice(0,4).map(pair),{y:c.fy(c.y-20,1010),big:c.num||String(Math.max(1,Math.round(c.B-c.A))),unit:c.say||'seconds'})},
  tags:{g:1,f:c=>BANK.tags(c.t,list(c,c.ws.slice(0,4)).slice(0,6).map((w,k)=>[w,c.A+.2*(k+1),k%2?640:110,c.FB+40+Math.floor(k/2)*120+(k%2)*40]),{until:c.B})},
  labelPlate:{g:1,f:c=>BANK.labelPlate(c.t,c.A,c.B,c.say||'follow',c.big,{y:c.fy(c.y,1100),sub:c.items[0]||''})},
  folder:{g:1,f:c=>{const s=c.say||c.big||'files';return BANK.folder(c.t,c.A,c.B,s.charAt(0).toUpperCase(),s.toLowerCase(),{y:c.fy(c.y-20,1000)})}},
  loader:{g:1,f:c=>BANK.loader(c.t,c.A,c.B,{y:c.fy(c.y,1000),text:c.say||'please wait…'})},
  chatPill:{f:c=>BANK.chatPill(c.t,c.A,c.B,c.say||c.raw,{y:190})},
  annotPin:{f:c=>BANK.annotPin(c.t,c.A,c.B,c.say||c.big.toLowerCase(),{x:120,y:300,to:[c.H.cx-90,c.H.top+60]})},
  pathRide:{g:1,f:c=>c.photos[0]?BANK.pathRide(c.t,c.A,c.B,c.photos[0],{y:c.fy(c.y+300,1300),label:c.say||c.big.toLowerCase(),w:300}):''},
  phone:{g:1,f:c=>c.photos[0]?BANK.phone(c.t,c.A,c.B,c.photos[0],{cy:c.fy(c.y+330,1250),w:340,cards:c.photos.slice(1,4)}):''},
  phoneCompare:{g:1,f:c=>c.photos.length>=2?BANK.phoneCompare(c.t,c.A,c.B,c.photos[0],c.photos[1],{y:c.fy(c.y+40,1100),w:380}):''},
  title:{g:1,f:c=>BANK.title(c.t,c.A,c.B,c.say||c.brow||c.w0,c.big,{top:c.fy(c.y+40,1150),color:c.color})},
  bracketWord:{g:1,f:c=>BANK.bracketWord(c.t,c.A,c.B,c.say||(c.w0.toLowerCase()===c.big.toLowerCase()?(c.ws.find(x=>x!==c.big.toLowerCase())||''):c.w0),c.big,{y:c.fy(c.y+60,1180),size:150})},
  ghostWord:{g:1,f:c=>BANK.ghostWord(c.t,c.A,c.B,c.say||c.big,{y:c.fy(c.y+70,1190),size:190,color:c.color})},
  noiseWord:{g:1,f:c=>BANK.noiseWord(c.t,c.A,c.B,c.say||c.big,{y:c.fy(c.y+70,1190),size:200,color:c.color})},
  focusWord:{g:1,f:c=>BANK.focusWord(c.t,c.A,c.B,(c.say||c.big).toUpperCase(),{y:c.fy(c.y+60,1200),size:260})},
  ribbon:{f:c=>BANK.ribbon(c.t,c.A,c.B,`M -80 ${c.FB+210} L 1160 ${c.FB+110}`,{twists:0,txt:(c.say||c.big).toUpperCase(),w:96,size:52})},
  ctaWord:{g:1,f:c=>BANK.ctaWord(c.t,c.A,c.B,(c.say||CTA.word||c.big).toUpperCase(),{small:'comment',y:c.fy(c.y+60,1200),color:c.color})},
  typeLine:{f:c=>BANK.typeLine(c.t,c.A,c.B,c.say||c.raw.toLowerCase())},
  behindWord:{b:c=>BANK.behindWord(c.t,c.A,c.B,(c.say||c.big).toUpperCase(),c.H,{eyebrow:c.brow,color:c.color})},
  walls:{b:c=>BANK.walls(c.t,c.A,c.B,(c.say||c.big).toUpperCase(),{color:c.color})},
  wordSwarm:{b:c=>BANK.wordSwarm(c.t,c.A,c.B,(c.say||c.big).toLowerCase(),{cx:c.H.cx,cy:820,color:c.color})},
  wordRing:{b:c=>BANK.wordRing(c.t,c.A,c.B,ringWords(c),c.H,{part:'back'}),f:c=>BANK.wordRing(c.t,c.A,c.B,ringWords(c),c.H,{part:'front'})},
  lineBehind:{b:c=>BANK.lineBehind(c.t,c.A,c.B,c.H,{label:c.say})},
  ring:{b:c=>BANK.ring(c.t,c.A,c.B,{cx:c.H.cx,cy:c.H.top+c.H.w*.62,r1:c.H.w*.78,r2:c.H.w*.92,label:c.say})},
  collage:{b:c=>c.photos.length?BANK.collage(c.t,c.A,c.B,c.photos.slice(0,8),{cx:c.H.cx,cy:800}):''},
  orbit:{b:c=>c.photos.length?BANK.orbit(c.t,c.A,c.B,c.photos.slice(0,8),orb(c,'back')):'',f:c=>c.photos.length?BANK.orbit(c.t,c.A,c.B,c.photos.slice(0,8),orb(c,'front')):''},
  duoCards:{b:c=>{if(c.photos.length<2)return '';const l=pair(c.items[0]||'before'),r=pair(c.items[1]||'after');return BANK.duoCards(c.t,c.A,c.B,[[c.photos[0],l[0],l[1]],[c.photos[1],r[0],r[1]]],{y:330})}},
  titleWall:{b:c=>BANK.titleWall(c.t,c.A,c.B,(c.say||c.big).toUpperCase(),(c.items[0]||'').toUpperCase(),{shots:c.photos.slice(0,3),headTop:c.H.top})},
  uiWall:{b:c=>c.photos.length?BANK.uiWall(c.t,c.A,c.B,c.photos,{title:c.say||'projects',label:c.items[0]||''}):''},
  split:{b:c=>BANK.split(c.t,c.A,c.B,splitInner(c),{split:.42})},
  sphere:{b:c=>BANK.sphere(c.t,c.A,c.B,{cx:c.H.cx,cy:780,R:420})},
  network:{b:c=>BANK.network(c.t,c.A,c.B,{cx:c.H.cx,cy:780})},
  shutter:{f:c=>c.photos.length?BANK.shutter(c.t,c.A,c.B,c.photos.slice(0,6)):''},
  carouselWide:{f:c=>c.photos.length?frost(c,BANK.carouselWide(c.t,c.A,c.B,c.photos.slice(0,8).map((s,k)=>[s,c.items[k]||'']),{title:(c.say||'').toUpperCase(),step:.5,CW:1080,CH:1920})):''},
  flowNodes:{f:c=>frost(c,BANK.flowNodes(c.t,c.A,c.B,list(c,c.ws.slice(0,4)).slice(0,4),{inL:c.say||''}))},
  phaseCards:{f:c=>frost(c,BANK.phaseCards(c.t,c.A,c.B,list(c,c.ws.slice(0,4)).slice(0,4).map((s,k)=>{const p=pair(s);return [p[0],'step '+(k+1),p[1]?p[1].split(/,\s*/).slice(0,3):[]]})))},
  halfBlur:{f:c=>{const it=c.items.length>=2?c.items.map(lead):[['',c.w0],['',c.w1]];return frost(c,BANK.halfBlur(c.t,c.A,c.B,it[0],it[1]))}},
  mapRoute:{f:c=>frost(c,BANK.mapRoute(c.t,c.A,c.B,{from:c.items[0]||'start',to:c.items[1]||c.big.toLowerCase(),eta:c.say||''}))},
  cta:{f:c=>{const msg=CTA.word?`comment ${CTA.word} · ${CTA.gift.toLowerCase()}`:'follow for more';const fu=faceU(Math.max(0,c.t-.3),c.t+.3);
   if(fu.b>1320||below(c.t)+470>1600)return BANK.chatPill(c.t,c.A,c.B,msg,{y:190});
   return CTA.word?BANK.labelPlate(c.t,c.A,c.B,'comment '+CTA.word,CTA.gift,{y:below(c.t),sub:'I’ll send it to your DMs'}):BANK.labelPlate(c.t,c.A,c.B,'follow','FOR MORE',{y:below(c.t),sub:'new reels every week'})}},
 };
 window.STUDIIO_EFFECTS=Object.keys(R);
 let SB=null,BEATS=[];
 const autoPlates=plates,autoBack=backPlates;
 const span=(b,k)=>{const last=k===BEATS.length-1;return b.effect==='cta'?[b.a,last?DUR+1:b.end]:[b.a+.15,(last?Math.min(b.end,DUR):b.end)-.05]};
 const ctx=(t,b,A,B)=>{const ws=b.ws,big=b.big||(ws[0]||'').toUpperCase();
  const FB=faceU(A,Math.min(B,DUR)).b;
  return {t,A,B,y:below(t),FB,fy:(v,c)=>Math.max(Math.min(v,c),FB+30),H:head(b.a,Math.min(b.end,DUR)),big,color:b.accent?'#F2632A':'#fff',brow:b.eyebrow,say:b.say,items:b.items,num:b.num,photos:b.photos,raw:b.raw,ws,w0:ws[0]||big,w1:ws[1]||big,w2:ws[2]||'done',txt:ws.join(' ')}};
 const run=(t,layer)=>{let h='';
  BEATS.forEach((b,k)=>{const r=R[b.effect];const fn=r&&r[layer];if(!fn)return;const [A,B]=span(b,k);if(!(t>A&&t<B))return;
   const c=ctx(t,b,A,B);
   if(layer==='f'&&r.g){const fu=faceU(Math.max(0,t-.3),t+.3);if(fu.b>1320||c.FB+20>c.y)return}   // close-up or no room under the chin
   try{h+=fn(c)}catch(e){console.error('STUDIIO beat '+(k+1)+' ('+b.effect+'):',e)}});
  if(layer==='f')(SB.vfx||[]).forEach(v=>{if(!(t>v.a&&t<v.b)||!v.caption)return;const cap=SAFE(v.caption);
   if(v.kind==='window')h+=BANK.windowCaption(t,v.a,v.b,cap);else if(v.kind==='grid')h+=BANK.gridTitle(t,v.a,v.b,cap)});
  return h};
 plates=t=>SB?run(t,'f'):autoPlates(t);
 backPlates=t=>SB?run(t,'b'):autoBack(t);
 window.STUDIIO_APPLY=doc=>{
  if(!doc||!Array.isArray(doc.beats)||!doc.beats.length){SB=null;return}
  SB=doc;
  BEATS=doc.beats.map(b=>{const f=b.fields||{};return {a:+b.a,end:+b.b,big:SAFE(b.big).trim().toUpperCase(),accent:!!b.accent,eyebrow:SAFE(b.eyebrow),sub:SAFE(b.sub),behind:!!b.behind,effect:String(b.effect||''),
   raw:SAFE(b.text),ws:keyWords(+b.a,+b.b).map(SAFE),say:SAFE(f.say),items:(f.items||[]).map(SAFE).filter(Boolean),num:SAFE(f.number),
   photos:(f.photos||[]).filter(p=>/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i.test(p)).map(p=>'photos/'+p)}});
  BIG.length=0;
  BEATS.forEach((b,k)=>BIG.push([b.a,k===BEATS.length-1?DUR+1:b.end,b.big?b.big.split(/\s+/).map(w=>b.accent?w+'*':w).join(' '):'',b.sub,b.eyebrow,b.behind?1:0]));
  window.SKIPBIG=new Set(BEATS.map((b,k)=>(!b.big||NOBIG.has(b.effect))?k:-1).filter(k=>k>=0));
  VFX.length=0;
  (doc.vfx||[]).forEach(v=>{const o={};if(v.color)o.color=v.color;VFX.push([+v.a,+v.b,String(v.kind),o])});
  window.VFX=VFX;
  CTA.word=SAFE(doc.cta&&doc.cta.word).toUpperCase();CTA.gift=SAFE(doc.cta&&doc.cta.gift)||'FREE GUIDE';
 };
 if(typeof STUDIIO_BEATS!=='undefined'&&!window.STUDIIO_BEATS_OFF)STUDIIO_APPLY(STUDIIO_BEATS);
})();
