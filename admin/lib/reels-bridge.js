// Turns STUDIIO_BEATS (the reel's beats.json) into the overlay: one big word and one effect per beat.
// Runs inside the Reels Montage PRO overlay template, at the end of its EDIT PER VIDEO block, and
// replaces plates()/backPlates() with renderers driven by the beats. The effect names match
// REEL_EFFECTS in admin/lib/reels.php. The live preview in the admin calls STUDIIO_APPLY(doc).
;(function(){
 window.STUDIIO_AUTO={big:BIG.map(r=>r.slice()),kinds:PH.map(p=>p.kind),raw:PH.map(p=>p.raw)};
 const SAFE=s=>String(s==null?'':s).replace(/</g,'‹').replace(/>/g,'›').replace(/"/g,'”').replace(/['`]/g,'’');
 const STOP=/^(the|and|did|not|but|you|was|are|for|with|this|that|its|it's|cup|all|just|one|what|how|has|had|can|our|your|will|need|don't|dont|have|make|made|get|got|there|they|them|then|than|from|into|about|really|very)$/;
 const keyWords=(a,b)=>W.filter(w=>w[0]>=a&&w[0]<b).map(w=>w[2].replace(/[.,?!]/g,'').toLowerCase()).filter(w=>w.length>2&&!STOP.test(w)).filter((w,k,arr)=>arr.indexOf(w)===k);
 const NOBIG=new Set(['title','bracketWord','ghostWord','noiseWord','focusWord','ribbon','ctaWord','typeLine','behindWord','walls','wordSwarm','titleWall','split','uiWall','duoCards','collage','shutter','carouselWide','flowNodes','phaseCards','halfBlur','mapRoute','spendGrid','dotMap','globeTags','wordCards','news','teardown','phoneWhip','sideCard']);
 const num=(s,d)=>{const m=String(s||'').match(/-?\d[\d.,]*/);return m?parseFloat(m[0].replace(/,/g,'')):d};
 const list=(c,fb)=>(c.items.length?c.items:fb).filter(Boolean);
 const pair=s=>{const m=String(s).match(/^(.*?)\s*[:—–]\s*(.+)$/);return m?[m[1].trim(),m[2].trim()]:[String(s),'']};
 const lead=s=>{const m=String(s).match(/^(\S+)\s+(.+)$/);return m?[m[1],m[2]]:[String(s),'']};
 const frost=(c,inner)=>BANK.scene(c.t,c.A,c.B,inner,{kind:'frost'});
 const CTA={word:'',gift:'FREE GUIDE'};
 // Places for dotMap and globeTags: a country or a big city (English) → [longitude, latitude].
 const PLACES={lebanon:[35.8,33.9],beirut:[35.5,33.9],uae:[54.4,24],emirates:[54.4,24],'united arab emirates':[54.4,24],dubai:[55.3,25.2],'abu dhabi':[54.4,24.5],
  'saudi arabia':[45,24],saudi:[45,24],ksa:[45,24],riyadh:[46.7,24.7],jeddah:[39.2,21.5],qatar:[51.2,25.3],doha:[51.5,25.3],kuwait:[47.6,29.3],bahrain:[50.6,26],oman:[57,21],muscat:[58.4,23.6],
  jordan:[36.5,31],amman:[35.9,31.95],syria:[38.5,35],damascus:[36.3,33.5],iraq:[44,33],baghdad:[44.4,33.3],palestine:[35.2,31.9],egypt:[30.8,26.8],cairo:[31.2,30],morocco:[-6.5,32],
  casablanca:[-7.6,33.6],algeria:[2.6,28],tunisia:[9.5,34],libya:[17,27],sudan:[30,15],turkey:[35,39],istanbul:[29,41],iran:[53,32],cyprus:[33,35],armenia:[45,40],
  'united kingdom':[-2,54],uk:[-2,54],england:[-1.5,52.5],britain:[-2,54],london:[-0.1,51.5],france:[2.2,46.6],paris:[2.35,48.85],germany:[10.4,51.2],berlin:[13.4,52.5],
  spain:[-3.7,40.4],madrid:[-3.7,40.4],barcelona:[2.2,41.4],italy:[12.5,42.8],rome:[12.5,41.9],milan:[9.2,45.5],netherlands:[5.3,52.1],amsterdam:[4.9,52.4],belgium:[4.5,50.6],
  switzerland:[8.2,46.8],geneva:[6.1,46.2],sweden:[15,62],norway:[9,61],denmark:[10,56],poland:[19,52],greece:[22,39],athens:[23.7,38],portugal:[-8,39.5],lisbon:[-9.1,38.7],
  ireland:[-8,53],austria:[14.5,47.5],russia:[100,60],moscow:[37.6,55.8],ukraine:[31,49],
  usa:[-98,39],us:[-98,39],'united states':[-98,39],america:[-98,39],'new york':[-74,40.7],'los angeles':[-118.2,34],california:[-119.5,37],miami:[-80.2,25.8],texas:[-99,31],
  canada:[-100,58],toronto:[-79.4,43.7],montreal:[-73.6,45.5],mexico:[-102,23.6],brazil:[-52,-10],'sao paulo':[-46.6,-23.5],argentina:[-64,-34],chile:[-71,-35],colombia:[-74,4.5],peru:[-75,-9],
  india:[78,21],china:[104,35],japan:[138,36],tokyo:[139.7,35.7],'south korea':[128,36],korea:[128,36],singapore:[103.8,1.35],indonesia:[117,-2],philippines:[122,12],thailand:[101,15],
  vietnam:[106,16],malaysia:[102,4],pakistan:[69,30],bangladesh:[90,24],australia:[134,-25],sydney:[151.2,-33.9],'new zealand':[174,-41],
  nigeria:[8,9.5],'south africa':[25,-29],kenya:[38,0.5],ghana:[-1,8],ethiopia:[39,9],senegal:[-14.5,14.5],'ivory coast':[-5.5,7.5]};
 const place=s=>{const k=String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/^the\s+/,'').replace(/[^a-z ]/g,' ').replace(/\s+/g,' ').trim();return PLACES[k]||null};
 // dotMap's dot grid (BANK._wm): longitude/latitude → [column, row], moved to the nearest dot of land.
 const mapCell=ll=>{const M=BANK._wm,R=M.length,C=M[0].length;let c=Math.floor((ll[0]+180)/360*C),r=Math.floor((82-ll[1])/(140/R));
  for(let d=0;d<=3;d++)for(let dr=-d;dr<=d;dr++)for(let dc=-d;dc<=d;dc++){const rr=r+dr,cc=c+dc;if(M[rr]&&M[rr][cc]==='X')return [cc,rr]}return [c,r]};
 const today=()=>new Date().toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}).toUpperCase();
 // STUDIIO's own effects (chipGrid … sideCard) are drawn here, not in the package: white UI cards and photo moves.
 const said=(A,B)=>W.filter(w=>w[0]>=A-.2&&w[0]<B);
 const nrm=s=>String(s).toLowerCase().replace(/[^a-z0-9]/g,'');
 // When the first word of a line is spoken inside the beat (null when it isn't said).
 const whenSaid=(s,A,B)=>{const k=nrm(String(s).split(/\s+/)[0]).slice(0,5);if(k.length<2)return null;const w=said(A,B).find(w=>{const x=nrm(w[2]);return x.startsWith(k)||(x.length>3&&k.startsWith(x))});return w?w[0]:null};
 const wide=c=>c.H.w<300;   // the face is small: a wide shot with room around the person
 const fan=c=>{const ph=c.photos.slice(0,5),n=ph.length;if(!n)return '';const s=/right/i.test(c.say)?1:-1,w=270,h=350,o=cl((c.B-c.t)/.25),W0=wide(c);
  const sp=(n-1)/2*78+w/2+70,px=Math.max(sp,Math.min(1080-sp,c.H.cx+s*(c.H.w*(W0?.6:.45)+(W0?250:200)))),py=W0?Math.min(1380,c.FB+170):Math.max(900,Math.min(1420,c.FB+80));
  return ph.map((src,k)=>{const j=k-(n-1)/2,a=c.A+.08+k*.12;if(c.t<a)return '';const e=eb((c.t-a)/.5);
   const x=c.H.cx+(px+j*78-c.H.cx)*e,y=py+40+(Math.abs(j)*24+Math.sin(c.t*1.6+k)*4-40)*e+(1-o)*40,r=(j*9+s*4)*e;
   return `<div class="a" style="left:${x-w/2}px;top:${y-h/2}px;width:${w}px;height:${h}px;box-sizing:border-box;border:6px solid #fff;border-radius:22px;overflow:hidden;background:#ddd;box-shadow:0 24px 50px rgba(0,0,0,.28);opacity:${cl((c.t-a)/.12)*o};transform:rotate(${r}deg) scale(${.5+.5*e})"><img src="${src}" style="width:100%;height:100%;object-fit:cover;display:block"></div>`}).join('')};
 // sideCard: the card slides in at the left; the words light up as they are said. Wide shot: words to the right of the card,
 // behind the head (as in the reference). Close-up: card and words in a column left of the face, in front.
 const side=(c,layer)=>{const src=c.photos[0],fx=faceU(c.A,Math.min(c.B,DUR)),W0=wide(c),x=50,w=Math.max(220,Math.min(420,fx.x-90)),h=Math.round(w*1.45);
  const y=Math.max(150,Math.min(W0?760:600,c.H.top-40)),k=eo((c.t-c.A)/.5),out=cl((c.t-(c.B-.3))/.3),dx=-(x+w+80)*(1-k)-(x+w+80)*out*out,o=cl((c.t-c.A-.15)/.2)*(1-out);
  const words=c.items.length?c.items.join(' ').split(/\s+/).filter(Boolean).map((t,i,arr)=>[t,c.A+.3+Math.max(.5,c.B-c.A-.9)*i/arr.length]):said(c.A,c.B).map(w=>[SAFE(w[2]),w[0]]);
  const card=src?`<div class="a" style="left:${x+dx}px;top:${y}px;width:${w}px;height:${h}px;border-radius:28px;overflow:hidden;box-shadow:0 30px 70px rgba(0,0,0,.3);transform:rotate(${-(1-k)*4}deg)"><img src="${src}" style="width:100%;height:100%;object-fit:cover;display:block"></div>`:'';
  const lit=words.map(([t,wt])=>`<span style="opacity:${c.t>=wt?1:.28}">${t}</span>`).join(' ');
  if(W0){if(layer!=='b')return '';const tx=Math.min(700,Math.max(src?x+w+44:x,fx.r+30));
   return card+`<div class="a" style="left:${tx}px;top:${y+6}px;width:${1080-60-tx}px;font:600 60px/1.08 Inter;letter-spacing:-.02em;color:#fff;text-shadow:0 2px 24px rgba(0,0,0,.35);opacity:${o}">${lit}</div>`}
  if(layer!=='f')return '';const tw=Math.max(w,fx.x-80);
  return card+`<div class="a" style="left:${x}px;top:${src?y+h+28:y}px;width:${tw}px;font:600 50px/1.1 Inter;letter-spacing:-.02em;color:#fff;text-shadow:0 2px 24px rgba(0,0,0,.45);opacity:${o}">${lit}</div>`};
 const CARD='background:#fff;color:#16161a;box-shadow:0 26px 60px rgba(0,0,0,.22),0 3px 10px rgba(0,0,0,.10)';
 const BLUE='#0095F6';
 const avatar=(src,name,d)=>src?`<img src="${src}" style="width:${d}px;height:${d}px;border-radius:50%;object-fit:cover;flex:none;display:block">`
  :`<div style="width:${d}px;height:${d}px;border-radius:50%;flex:none;background:linear-gradient(135deg,#F2632A,#C13584);color:#fff;font:600 ${Math.round(d*.42)}px/1 Inter;display:flex;align-items:center;justify-content:center">${(String(name||'S').trim()[0]||'S').toUpperCase()}</div>`;
 const svg=(d,fill)=>`<svg width="30" height="30" viewBox="0 0 24 24" style="flex:none;display:block" fill="${fill?'#16161a':'none'}" stroke="#16161a" stroke-width="${fill?0:2}" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
 const icon=s=>{s=String(s).toLowerCase();
  if(/transition|cut|edit|trim/.test(s))return svg('<rect x="3" y="7" width="11" height="11" rx="2.5"/><rect x="10" y="4" width="11" height="11" rx="2.5"/>');
  if(/sound|audio|music|voice|sfx/.test(s))return svg('<path d="M4 10v4M8 7v10M12 4v16M16 8v8M20 11v2"/>');
  if(/motion|graphic|animat|effect|design|visual/.test(s))return svg('<path d="M12 2.5l2.2 6.3 6.3 2.2-6.3 2.2L12 19.5l-2.2-6.3L3.5 11l6.3-2.2z"/>',1);
  if(/story|script|hook|caption|copy|writ/.test(s))return svg('<path d="M5 4h10l4 4v12H5z"/><path d="M8 11h8M8 15h6"/>');
  if(/camera|film|shoot|video|footage/.test(s))return svg('<rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10l5-3v10l-5-3z"/>');
  if(/grow|result|sale|lead|money|view|reach/.test(s))return svg('<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>');
  return '<span style="width:16px;height:16px;border-radius:50%;background:#F2632A;flex:none;display:block"></span>'};
 // "12.4K" counts up from 0 to 12.4 and keeps the K; k runs 0 → 1.
 const countUp=(v,k)=>{const m=String(v).match(/^([^\d]*)([\d][\d.,]*)(.*)$/);if(!m)return v;const n=parseFloat(m[2].replace(/,/g,'')),dec=(m[2].split('.')[1]||'').length,x=n*eo(k);
  return m[1]+(dec?x.toFixed(dec):Math.round(x).toLocaleString('en-US'))+m[3]};
 // Sound cues for STUDIIO's own effects: beat → [[seconds, sound, volume], …] (sounds as in engine/sfx2.py).
 const CUES={
  chipGrid:b=>(b.items.length?b.items:b.ws.slice(0,4)).slice(0,6).map((s,k)=>[whenSaid(s,b.a+.15,b.end)??(b.a+.4+k*.45),'pop',.3]),
  profileCard:b=>[[b.a+.15,'pop',.3],[b.a+.6,'air',.25]],
  commentBar:b=>{const w=String(b.say||CTA.word||b.big||'GUIDE').slice(0,14),t0=b.a+.6,cps=Math.max(9,w.length/Math.max(.3,b.end-.05-1.25-t0));return [[b.a+.15,'air',.3],...[...w].map((_,i)=>[t0+i/cps,'type',.25]),[t0+w.length/cps+.3,'pop',.35]]},
  propIn:b=>[[b.a+.15,'air',.35],[b.a+.6,'sub',.2]],
  phoneWhip:b=>[[b.a+.15,'air',.45]],
  photoFan:b=>[[b.a+.2,'air',.35]],
  sideCard:b=>[[b.a+.15,'air',.35]],
 };
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
  // Totals as "TOTAL: $162", tools as "Claude, code: $20".
  spendGrid:{g:1,f:c=>{const stats=[],tools=[];c.items.forEach(s=>{const m=s.match(/^(.+?),\s*(.+?)\s*[:—–]\s*(.+)$/);if(m)tools.push([m[1],m[2],m[3]]);else{const p=pair(s);stats.push([p[0].toUpperCase(),p[1]])}});
   if(!stats.length)stats.push(['TOTAL',c.num||'']);const sub=(c.big||'tools').toLowerCase();
   return BANK.spendGrid(c.t,c.A,c.B,stats.slice(0,4),tools.slice(0,6),{y:c.fy(c.y-60,960),title:c.say||'Costs',sub:sub.charAt(0).toUpperCase()+sub.slice(1)})}},
  // Places as "Lebanon: 43%"; the label is the place, the number the value.
  dotMap:{f:c=>{const pins=[];c.items.slice(0,4).forEach((s,k)=>{const p=pair(s),ll=place(p[0]);if(!ll)return;const [col,row]=mapCell(ll);pins.push([col,row,p[1]||'',p[0],c.A+.6+k*.35,row<6?240:(k%2?240:-240)])});
   return frost(c,BANK.dotMap(c.t,c.A,c.B,pins,{title:c.say||''}))}},
  // Tags around the globe; "Beirut, London" draws the arc between the two places.
  globeTags:{f:c=>{const tags=list(c,c.ws.slice(0,5)).slice(0,6),n=tags.length||1;const [p1,p2]=String(c.say||'').split(/\s*(?:,|→|->|\bto\b)\s*/i);
   const from=place(p1)||[35.5,33.9],to=place(p2)||[55.3,25.2];
   return frost(c,BANK.globeTags(c.t,c.A,c.B,tags.map((w,k)=>{const a=(-110+k*360/n)*Math.PI/180;return [w,Math.round(Math.cos(a)*400),Math.round(Math.sin(a)*470)]}),{from,to}))}},
  // Cards as "Basic: $500: 4 reels, 1 visit, captions"; the number picks the highlighted card.
  wordCards:{f:c=>{const cards=list(c,[c.w0,c.w1,c.w2]).slice(0,3).map(s=>{const m=String(s).split(/\s*:\s*/);return [m[0]||'',m[1]||'',(m.slice(2).join(': ')||'').split(/\s*,\s*/).filter(Boolean).slice(0,4)]});
   const hot=Math.min(cards.length,Math.max(1,num(c.num,Math.min(2,cards.length))))-1;
   return frost(c,BANK.wordCards(c.t,c.A,c.B,c.say||(c.big||'Pricing'),cards,{hot}))}},
  // First line the subhead, then the text; *stars* highlight words; "Paper:" and "Caption:" lines are optional.
  news:{f:c=>{let paper='THE DAILY',cap='';const marks=[],lines=[];c.items.forEach(s=>{const m=s.match(/^(paper|caption)\s*:\s*(.+)$/i);if(m){if(m[1].toLowerCase()==='paper')paper=m[2].toUpperCase();else cap=m[2]}else lines.push(s)});
   const clean=s=>String(s||'').replace(/\*([^*]+)\*/g,(_,w)=>{marks.push(w);return w});const deck=clean(lines[0]),body=clean(lines.slice(1).join(' '));
   return frost(c,BANK.newsHighlight(c.t,c.A,c.B,{paper,date:today(),head:c.say||c.big||c.raw,deck,body,marks,src:c.photos[0]||'',cap}))}},
  // Layers as "Captions: one word at a time"; the drawing follows the name; a photo fills the footage layer.
  teardown:{f:c=>{const kind=n=>/caption|subtitle|text/i.test(n)?'captions':/effect|graphic|motion|title/i.test(n)?'effects':/sound|music|audio|sfx|voice/i.test(n)?'sound':/cut|edit|trim/i.test(n)?'cuts':'footage';
   const it=(c.items.length?c.items:['captions: one word at a time','effects: glass plates','sound: quiet sfx','cuts: pauses removed','footage: your clip']).slice(0,5).map(s=>{const p=pair(s);return [p[0],p[1],kind(p[0])]});
   return frost(c,BANK.teardown(c.t,c.A,c.B,it,{src:c.photos[0]||''}))}},
  // ---- STUDIIO's own effects ----
  // White chips with an icon, two columns at chest height; each pops in when its first word is said.
  chipGrid:{g:1,f:c=>{const it=list(c,c.ws.slice(0,4)).slice(0,6),top=c.fy(c.y,1180),cx=Math.max(420,Math.min(660,c.H.cx)),o=cl((c.B-c.t)/.2);
   return it.map((s,k)=>{const a=whenSaid(s,c.A,c.B)??(c.A+.25+k*.45);if(c.t<a-.02)return '';const e=eb((c.t-a)/.38),col=k%2,y=top+Math.floor(k/2)*96;
    return `<div class="a" style="${col?`left:${cx+10}px`:`right:${1080-cx+10}px`};top:${y}px;display:flex;align-items:center;gap:14px;padding:16px 30px 16px 22px;border-radius:999px;${CARD};font:600 32px/1 Inter;white-space:nowrap;opacity:${cl((c.t-a)/.1)*o};transform:translateY(${(1-e)*24}px) scale(${.55+.45*e});transform-origin:${col?'0%':'100%'} 50%">${icon(s)}${s}</div>`}).join('')}},
  // A profile card: pops in as a small pill with the name, then opens to show the stats counting up.
  profileCard:{g:1,f:c=>{const name=c.say||'Your Name';let handle='';const stats=[];
   c.items.forEach(s=>{if(/^@/.test(s)||/^handle\s*:/i.test(s))handle=s.replace(/^handle\s*:\s*/i,'');else{const p=pair(s);if(p[1])stats.push(p)}});
   if(!stats.length)stats.push(['Posts','248'],['Followers','12.4K'],['Following','310']);
   const top=c.fy(c.y,1150),cx=Math.max(420,Math.min(660,c.H.cx)),k1=eb((c.t-c.A)/.4),k2=eo((c.t-c.A-.45)/.45),o=cl((c.t-c.A)/.1)*cl((c.B-c.t)/.2),w=430+330*k2,h=104+160*k2;
   return `<div class="a" style="left:${cx-w/2}px;top:${top}px;width:${w}px;height:${h}px;border-radius:34px;${CARD};overflow:hidden;opacity:${o};transform:scale(${.3+.7*k1});transform-origin:50% 0">
    <div style="display:flex;align-items:center;gap:20px;padding:20px 24px">${avatar(c.photos[0],name,64)}<div style="flex:1;min-width:0"><div style="font:600 32px/1.1 Inter;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${name}</div>${handle?`<div style="font:400 24px/1.3 Inter;color:#737373;white-space:nowrap">${handle}</div>`:''}</div>
    ${k2>.05?`<div style="opacity:${k2};background:${BLUE};color:#fff;font:600 26px/1 Inter;padding:14px 26px;border-radius:14px;flex:none">Follow</div>`:''}</div>
    <div style="display:flex;justify-content:space-around;padding:4px 20px 0;opacity:${k2}">${stats.slice(0,3).map(([l,v])=>`<div style="text-align:center;min-width:150px"><div style="font:600 42px/1.15 Inter">${countUp(v,cl((c.t-c.A-.6)/.8))}</div><div style="font:400 24px/1.4 Inter;color:#737373">${l}</div></div>`).join('')}</div></div>`}},
  // A comment bar: types the code word, presses Post, and the comment pops above it.
  commentBar:{g:1,f:c=>{const word=String(c.say||CTA.word||c.big||'GUIDE').toUpperCase().slice(0,14),top=c.fy(c.y,1200),w=860,x=(1080-w)/2,e=eb((c.t-c.A)/.45),o=cl((c.t-c.A)/.1)*cl((c.B-c.t)/.2);
   const t0=c.A+.45,cps=Math.max(9,word.length/Math.max(.3,c.B-1.25-t0)),typed=ty(word,c.t,t0,cps),done=typed.length>=word.length,sent=t0+word.length/cps+.3,kb=eb((c.t-sent)/.35),cur=!done&&Math.floor(c.t*3)%2?'|':'';
   let h=`<div class="a" style="left:${x}px;top:${top}px;width:${w}px;height:100px;box-sizing:border-box;border-radius:50px;${CARD};display:flex;align-items:center;gap:18px;padding:0 34px 0 18px;opacity:${o};transform:translateY(${(1-e)*40}px)">${avatar(c.photos[0],word,64)}
    <div style="flex:1;font:${typed?500:400} 32px/1 Inter;color:${typed?'#16161a':'#9a9a9f'};white-space:nowrap">${typed?typed+cur:'Add a comment…'}</div><div style="font:600 30px/1 Inter;color:${BLUE};opacity:${done?1:.35};transform:scale(${Math.abs(c.t-sent)<.1?.9:1})">Post</div></div>`;
   if(c.t>sent)h+=`<div class="a" style="right:${x+30}px;top:${top-82}px;background:#3A55F5;color:#fff;font:600 30px/1 Inter;padding:17px 28px;border-radius:22px 22px 6px 22px;box-shadow:0 14px 30px rgba(58,85,245,.35);opacity:${cl((c.t-sent)/.1)*o};transform:scale(${.4+.6*kb});transform-origin:100% 100%">${word}</div>`;
   return h}},
  // A cut-out PNG rises from the bottom in front of the person, settles, and drops away at the end.
  propIn:{f:c=>{const src=c.photos[0];if(!src)return '';const w=1080*cl(num(c.num,60)/100,.25,1),side=/left/i.test(c.say)?-1:/cent/i.test(c.say)?0:1;
   const x=side<0?40:side>0?1080-w-40:(1080-w)/2,e=eb((c.t-c.A)/.6),out=cl((c.t-(c.B-.35))/.35),dy=(1-e)*1000+out*out*1200,r=(1-eo((c.t-c.A)/.6))*side*6;
   return `<img class="a" src="${src}" style="left:${x}px;bottom:-40px;width:${w}px;transform:translateY(${dy}px) rotate(${r}deg) scale(${1+.03*cl((c.t-c.A)/Math.max(.5,c.B-c.A))});transform-origin:50% 100%;filter:drop-shadow(0 30px 40px rgba(0,0,0,.35));opacity:${cl((c.t-c.A)/.08)}">`}},
  // A phone whips in from the left with motion blur, holds your screenshot, and whips out to the right.
  phoneWhip:{f:c=>{const w=500,h=Math.round(w*2.06),k=eo((c.t-c.A)/.42),out=cl((c.t-(c.B-.3))/.3),oe=out*out,top=Math.max(520,Math.min(820,c.FB-120)),x0=560-w/2;
   const x=-w-160+(x0+w+160)*k+oe*(1080-x0+80),bl=(1-k)*24+oe*24,sk=-(1-k)*12+oe*12,src=c.photos[0];
   const screen=src?`<img src="${src}" style="width:100%;height:100%;object-fit:cover;display:block">`
    :`<div style="width:100%;height:100%;background:linear-gradient(160deg,#2b2d33,#111);display:flex;align-items:center;justify-content:center;font:700 96px/1 Antonio;color:#fff">${c.big}</div>`;
   return `<div class="a" style="left:${x}px;top:${top}px;width:${w}px;height:${h}px;box-sizing:border-box;padding:16px;border-radius:80px;background:#141416;box-shadow:0 0 0 4px #b9b4ad,0 0 0 6px #6d6a66,0 50px 90px rgba(0,0,0,.45);transform:rotate(${-4-(1-k)*6}deg) skewX(${sk}deg);filter:blur(${bl}px)">
    <div style="position:relative;width:100%;height:100%;border-radius:64px;overflow:hidden;background:#000">${screen}
    <div style="position:absolute;left:50%;top:18px;width:150px;height:42px;margin-left:-75px;border-radius:21px;background:#000"></div>
    <div style="position:absolute;inset:0;background:linear-gradient(115deg,rgba(255,255,255,.16),rgba(255,255,255,0) 38%)"></div></div></div>`}},
  // Photos fan out from behind the person to one side. Wide shot: behind the person; close-up (no room behind): in front, beside the body.
  photoFan:{b:c=>wide(c)?fan(c):'',f:c=>wide(c)?'':fan(c)},
  // A tall photo slides in from the left edge; beside it the words light up as they are said.
  sideCard:{b:c=>side(c,'b'),f:c=>side(c,'f')},
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
 let OWN=[];   // sound cues this bridge added to SFX (taken back out when the beats change)
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
  if(typeof SFX!=='undefined'){OWN.forEach(x=>{const i=SFX.indexOf(x);if(i>=0)SFX.splice(i,1)});OWN=[];
   BEATS.forEach(b=>{const f=CUES[b.effect];if(!f)return;try{f(b).forEach(([t,k,v])=>{if(t>=0&&t<DUR){const x=[+t.toFixed(3),k,v];SFX.push(x);OWN.push(x)}})}catch(e){}})}
 };
 if(typeof STUDIIO_BEATS!=='undefined'&&!window.STUDIIO_BEATS_OFF)STUDIIO_APPLY(STUDIIO_BEATS);
})();
