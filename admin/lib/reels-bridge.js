// Turns STUDIIO_BEATS (the reel's beats.json) into the overlay: one big word and one effect per beat.
// Runs inside the Reels Montage PRO overlay template, at the end of its EDIT PER VIDEO block, and
// replaces plates()/backPlates() with renderers driven by the beats. The effect names match
// REEL_EFFECTS in admin/lib/reels.php. The live preview in the admin calls STUDIIO_APPLY(doc).
;(function(){
 window.STUDIIO_AUTO={big:BIG.map(r=>r.slice()),kinds:PH.map(p=>p.kind),raw:PH.map(p=>p.raw)};
 const SAFE=s=>String(s==null?'':s).replace(/</g,'‹').replace(/>/g,'›').replace(/"/g,'”').replace(/['`]/g,'’');
 const STOP=/^(the|and|did|not|but|you|was|are|for|with|this|that|its|it's|cup|all|just|one|what|how|has|had|can|our|your|will|need|don't|dont|have|make|made|get|got|there|they|them|then|than|from|into|about|really|very)$/;
 const keyWords=(a,b)=>W.filter(w=>w[0]>=a&&w[0]<b).map(w=>w[2].replace(/[.,?!]/g,'').toLowerCase()).filter(w=>w.length>2&&!STOP.test(w)).filter((w,k,arr)=>arr.indexOf(w)===k);
 const NOBIG=new Set(['title','bracketWord','ghostWord','noiseWord','focusWord','ribbon','ctaWord','typeLine','behindWord','walls','wordSwarm','titleWall','split','uiWall','duoCards','collage','shutter','carouselWide','flowNodes','phaseCards','halfBlur','mapRoute','spendGrid','dotMap','globeTags','wordCards','news','teardown']);
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
