import{s as ut,bE as ct,bF as pt,r as c,b_ as L,o as mt,j as y,c as p,e as $,m as s,p as i,q as v,l as o,x as d,bV as a,F as V,z as j,P,k as n,bB as vt,Q as _t,y as lt,v as yt,bU as H,A as K,bK as bt,bP as ft}from"../app.Dvs3ZuQn.js";import{_ as ht}from"./PageHeader.BBxLuE-H.js";import{_ as gt}from"./DateRangePicker.CaSTG_Yx.js";import{_ as xt}from"./ViewCurrencySelect.CMe3dZ9c.js";import{e as wt,a as $t}from"./exporters.BxUZG1mZ.js";import{P as Tt}from"./PrinterOutlined.lEP9r_vT.js";import{F as kt}from"./FileExcelOutlined.BVUHvJ8-.js";import{F as St}from"./FilePdfOutlined.BUKSAcx6.js";import{N as Pt}from"./NumberOutlined.DNDFxNFc.js";import{D as Ct}from"./DollarOutlined.BKwp2Dct.js";const Dt={class:"page"},qt={class:"kpi-inner"},zt={class:"kpi-text"},It={class:"kpi-value"},Nt={key:1,class:"summary-table-wrap"},Ot={class:"summary-table"},Rt={style:{width:"55%"}},Et={class:"num",style:{width:"15%"}},Ut={class:"num",style:{width:"30%"}},Yt={class:"group-row"},Ft={colspan:"3"},At={class:"num"},Gt={class:"num"},Bt={class:"subtotal-row"},Mt={class:"num bold"},Vt={class:"num bold"},Qt={class:"num bold"},Lt={class:"grand-row"},jt={class:"num bold"},Ht={class:"num bold"},Kt={class:"num bold"},Wt={__name:"ProductsSoldSummaryReport",setup(Jt){const{t:g}=ct(),{money:q,number:ot}=pt(),C=c([L().startOf("day"),L()]),R=c(void 0),E=c(void 0),U=c(void 0),Y=c(void 0),F=c(""),Q=c(80),x=c(!0),A=c(""),z=c([]),_=c({quantity:0,total:0,items:0}),r=c({}),f=c({}),W=c([]),J=c([]),X=c([]),st=K(()=>[{value:"completed",label:a("Completed","Completed")},{value:"pending",label:a("Pending","Pending")},{value:"ordered",label:a("Ordered","Ordered")}]),Z=e=>(e||[]).map(t=>({value:t.id,label:t.name})),nt=K(()=>(J.value||[]).map(e=>({value:e.id,label:e.username})));function T(e){const t=Number(e||0);return Number.isInteger(t)?String(t):t.toFixed(2)}function G(e){return e.category_id===null?a("Uncategorized","Uncategorized"):e.category_name}const rt=K(()=>[{key:"items",label:a("Items_Sold","Items sold"),value:_.value.items,icon:bt,color:"#1677ff",tint:"rgba(22, 119, 255, 0.12)"},{key:"qty",label:a("Total_Qty","Total qty"),value:T(_.value.quantity),icon:Pt,color:"#13c2c2",tint:"rgba(19, 194, 194, 0.12)"},{key:"total",label:a("Grand_Total","Grand total"),value:q(_.value.total),icon:Ct,color:"#6d28d9",tint:"rgba(109, 40, 217, 0.12)"},{key:"trans",label:`${a("Transactions","Transactions")} / ${a("Cust_Count","Customers")}`,value:`${r.value.transaction_count??0} / ${r.value.customer_count??0}`,icon:ft,color:"#22c55e",tint:"rgba(34, 197, 94, 0.12)"}]),it=()=>{var e,t;return{...(e=C.value)!=null&&e[0]?{from:C.value[0].format("YYYY-MM-DD")}:{},...(t=C.value)!=null&&t[1]?{to:C.value[1].format("YYYY-MM-DD")}:{},...R.value?{warehouse_id:R.value}:{},...E.value?{user_id:E.value}:{},...U.value?{category_id:U.value}:{},...Y.value?{statut:Y.value}:{},...F.value?{search:F.value}:{}}};async function k(){x.value=!0;try{const e=await yt.get("report/products_sold_summary",it());z.value=Array.isArray(e.categories)?e.categories:[],_.value=e.totals||{quantity:0,total:0,items:0},r.value=e.meta||{},f.value=e.company||{},W.value=e.warehouses||[],J.value=e.users||[],X.value=e.categories_list||[]}catch(e){H.error((e==null?void 0:e.message)||"Error")}finally{x.value=!1}}function tt(){const e=[];return z.value.forEach(t=>{t.items.forEach(m=>e.push({category_name:G(t),product_code:m.product_code||"",product_name:m.product_name,unit:m.unit||"",quantity:m.quantity,total:m.total})),e.push({category_name:G(t),product_code:"",product_name:a("Sub_Total","Sub Total"),unit:"",quantity:t.sub_total_quantity,total:t.sub_total})}),e.length&&e.push({category_name:"",product_code:"",product_name:a("Grand_Total","Grand Total"),unit:"",quantity:_.value.quantity,total:_.value.total}),e}const et=()=>[{title:g("Categorie"),dataIndex:"category_name"},{title:g("Code"),dataIndex:"product_code"},{title:g("ProductName"),dataIndex:"product_name"},{title:a("Unit","Unit"),dataIndex:"unit"},{title:g("Quantity"),dataIndex:"quantity"},{title:g("Total"),dataIndex:"total",exportValue:e=>q(e.total)}];async function at(e){A.value=e;try{const t=`products-sold-summary_${r.value.from}_${r.value.to}`;e==="excel"?await wt(t,et(),tt()):await $t(a("products_sold_summary","Products Sold Summary"),et(),tt())}catch(t){H.error((t==null?void 0:t.message)||"Export failed")}finally{A.value=""}}function dt(){const e=window.open("","_blank","width=420,height=640");if(!e){H.error("Please allow popups to print");return}const t=u=>String(u??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"),m=Number(Q.value)||80,S=u=>ot(u),I=r.value.day_status==="open"?a("Day_is_still_opened","Day is still opened"):r.value.day_status==="closed"?a("Day_is_closed","Day is closed"):"",h=(u,l)=>`<div class="kv"><span class="k">${t(u)}</span><span class="s">:</span><span class="v">${t(l)}</span></div>`;let b="";z.value.forEach(u=>{b+=`<div class="cat">${t(G(u))}</div>`,b+='<table class="items">',u.items.forEach(l=>{b+=`<tr>
        <td class="name">${t(l.product_name)}</td>
        <td class="qty">${t(T(l.quantity))}</td>
        <td class="amt">${t(S(l.total))}</td>
      </tr>`}),b+=`<tr class="sub">
        <td class="name">${t(a("Sub_Total","Sub Total"))}</td>
        <td class="qty">${t(T(u.sub_total_quantity))}</td>
        <td class="amt">${t(S(u.sub_total))}</td>
      </tr>`,b+="</table>"});const D=r.value.from===r.value.to?r.value.from:`${r.value.from} — ${r.value.to}`,B=L().format("DD-MM-YYYY HH:mm:ss"),N=e.document;N.open(),N.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>${t(a("Day_Report","Day Report"))}</title>
    <style>
      @page { size: ${m}mm auto; margin: 0; }
      @media print { body, body * { visibility: visible !important; } }
      * { box-sizing: border-box; }
      body {
        width: ${m}mm;
        margin: 0;
        padding: 3mm 2mm;
        font-family: "Courier New", Courier, monospace;
        font-size: 11px;
        line-height: 1.35;
        color: #000;
        background: #fff;
      }
      .center { text-align: center; }
      .company { font-weight: bold; font-size: 12px; }
      .rule { border-top: 1px dashed #000; margin: 4px 0; }
      .title { font-weight: bold; font-size: 14px; margin: 4px 0 6px; }
      .kv { display: flex; }
      .kv .k { flex: 0 0 42%; text-transform: uppercase; }
      .kv .s { flex: 0 0 4%; }
      .kv .v { flex: 1 1 auto; }
      .banner { text-align: center; font-weight: bold; text-transform: uppercase; margin: 2px 0; }
      .section { font-weight: bold; text-transform: uppercase; margin: 6px 0 2px; }
      .cat { font-weight: bold; text-decoration: underline; margin-top: 6px; }
      table.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
      table.items td { vertical-align: top; padding: 1px 0; word-wrap: break-word; }
      td.name { width: 58%; }
      td.qty  { width: 12%; text-align: right; padding-right: 2mm; }
      td.amt  { width: 30%; text-align: right; }
      tr.sub td { border-top: 1px solid #000; font-weight: bold; padding-top: 2px; }
      .grand { display: flex; justify-content: space-between; font-weight: bold;
               font-size: 12px; border-top: 1px solid #000; border-bottom: 1px solid #000;
               padding: 3px 0; margin-top: 6px; }
      .foot { text-align: center; margin-top: 8px; font-size: 10px; }
    </style>
  </head>
  <body>
    <div class="center company">${t(f.value.name)}</div>
    <div class="center">${t(f.value.address)}</div>
    <div class="center">${t(f.value.email)}</div>
    <div class="center">${t(f.value.phone)}</div>
    ${f.value.vat_number?`<div class="center">VAT/BIN: ${t(f.value.vat_number)}</div>`:""}
    ${f.value.website?`<div class="center">${t(f.value.website)}</div>`:""}

    <div class="title">${t(a("Day_Report","Day Report"))}</div>

    ${h(a("Till","Till"),r.value.till||g("All"))}
    ${h(a("Sales_Person","Sales person"),r.value.sales_person||g("All"))}
    ${h(a("Cust_Count","Cust. count"),r.value.customer_count??0)}
    ${h(a("Transactions","Transactions"),r.value.transaction_count??0)}
    ${h(a("Trans_Date","Trans. date"),D)}
    ${h(a("Printed_On","Printed on"),B)}
    ${h(a("Printed_By","Printed by"),r.value.printed_by||"")}

    ${I?`<div class="rule"></div><div class="banner">${t(I)}</div>`:""}
    <div class="rule"></div>

    <div class="section">${t(g("Sales"))}</div>
    ${b||'<div class="center">—</div>'}

    <div class="grand">
      <span>${t(a("Grand_Total","Grand Total"))}</span>
      <span>${t(S(_.value.total))}</span>
    </div>

    <div class="foot">${t(a("Items_Sold","Items sold"))}: ${t(_.value.items)} &nbsp;·&nbsp; ${t(a("Total_Qty","Total qty"))}: ${t(T(_.value.quantity))}</div>
  </body>
</html>`),N.close(),e.focus();let M=!1;const O=()=>{if(!M){M=!0;try{e.close()}catch{}}};try{e.onafterprint=O}catch{}setTimeout(()=>{try{e.print()}catch{}setTimeout(O,6e4)},400)}return mt(k),(e,t)=>{const m=y("a-select"),S=y("a-button"),I=y("a-space"),h=y("a-input-search"),b=y("a-card"),D=y("a-typography-text"),B=y("a-spin"),N=y("a-col"),M=y("a-row"),O=y("a-tag"),u=y("a-empty");return p(),$("div",Dt,[s(ht,{title:d(a)("products_sold_summary","Products Sold Summary"),breadcrumb:[e.$t("Reports"),d(a)("products_sold_summary","Products Sold Summary")]},{actions:i(()=>[s(I,{wrap:""},{default:i(()=>[s(xt),s(m,{value:Q.value,"onUpdate:value":t[0]||(t[0]=l=>Q.value=l),style:{width:"100px"},options:[{value:58,label:"58 mm"},{value:80,label:"80 mm"},{value:88,label:"88 mm"}]},null,8,["value"]),s(S,{type:"primary",disabled:x.value,onClick:dt},{icon:i(()=>[s(d(Tt))]),default:i(()=>[v(" "+o(d(a)("Print_Receipt","Print Receipt")),1)]),_:1},8,["disabled"]),s(S,{loading:A.value==="excel",disabled:x.value,onClick:t[1]||(t[1]=l=>at("excel"))},{icon:i(()=>[s(d(kt))]),default:i(()=>[v(" "+o(e.$t("Export"))+" Excel ",1)]),_:1},8,["loading","disabled"]),s(S,{loading:A.value==="pdf",disabled:x.value,onClick:t[2]||(t[2]=l=>at("pdf"))},{icon:i(()=>[s(d(St))]),default:i(()=>[v(" "+o(e.$t("Export"))+" PDF ",1)]),_:1},8,["loading","disabled"])]),_:1})]),_:1},8,["title","breadcrumb"]),s(b,{size:"small",style:{"margin-bottom":"16px"}},{default:i(()=>[s(I,{wrap:""},{default:i(()=>[s(gt,{value:C.value,"onUpdate:value":t[3]||(t[3]=l=>C.value=l),"allow-clear":"",onChange:k},null,8,["value"]),s(m,{value:R.value,"onUpdate:value":t[4]||(t[4]=l=>R.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:d(a)("Till","Till"),options:Z(W.value),onChange:k},null,8,["value","placeholder","options"]),s(m,{value:E.value,"onUpdate:value":t[5]||(t[5]=l=>E.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:d(a)("Sales_Person","Sales person"),options:nt.value,onChange:k},null,8,["value","placeholder","options"]),s(m,{value:U.value,"onUpdate:value":t[6]||(t[6]=l=>U.value=l),style:{width:"180px"},"allow-clear":"","show-search":"","option-filter-prop":"label",placeholder:e.$t("Categorie"),options:Z(X.value),onChange:k},null,8,["value","placeholder","options"]),s(m,{value:Y.value,"onUpdate:value":t[7]||(t[7]=l=>Y.value=l),style:{width:"150px"},"allow-clear":"",placeholder:d(a)("Sale_Status","Sale status"),options:st.value,onChange:k},null,8,["value","placeholder","options"]),s(h,{value:F.value,"onUpdate:value":t[8]||(t[8]=l=>F.value=l),style:{width:"220px"},"allow-clear":"",placeholder:e.$t("Search"),onSearch:k},null,8,["value","placeholder"])]),_:1})]),_:1}),s(M,{gutter:[16,16],style:{"margin-bottom":"16px"}},{default:i(()=>[(p(!0),$(V,null,j(rt.value,l=>(p(),P(N,{key:l.key,xs:12,sm:12,md:6},{default:i(()=>[s(b,{size:"small",class:"kpi-card"},{default:i(()=>[n("div",qt,[n("div",{class:"kpi-icon",style:vt({background:l.tint,color:l.color})},[(p(),P(_t(l.icon)))],4),n("div",zt,[s(D,{type:"secondary",class:"kpi-label"},{default:i(()=>[v(o(l.label),1)]),_:2},1024),n("div",It,[x.value?(p(),P(B,{key:0,size:"small"})):(p(),$(V,{key:1},[v(o(l.value),1)],64))])])])]),_:2},1024)]),_:2},1024))),128))]),_:1}),s(b,{size:"small"},{title:i(()=>[v(o(d(a)("Day_Report","Day Report"))+" ",1),r.value.day_status==="open"?(p(),P(O,{key:0,color:"warning",style:{"margin-left":"8px"}},{default:i(()=>[v(o(d(a)("Day_is_still_opened","Day is still opened")),1)]),_:1})):r.value.day_status==="closed"?(p(),P(O,{key:1,style:{"margin-left":"8px"}},{default:i(()=>[v(o(d(a)("Day_is_closed","Day is closed")),1)]),_:1})):lt("",!0)]),extra:i(()=>[s(D,{type:"secondary",style:{"font-size":"12px"}},{default:i(()=>[v(o(d(a)("Till","Till"))+": "+o(r.value.till||e.$t("All"))+" · "+o(d(a)("Sales_Person","Sales person"))+": "+o(r.value.sales_person||e.$t("All"))+" · "+o(r.value.from)+" → "+o(r.value.to),1)]),_:1})]),default:i(()=>[s(B,{spinning:x.value},{default:i(()=>[!z.value.length&&!x.value?(p(),P(u,{key:0,description:e.$t("NodataAvailable"),style:{padding:"48px 0"}},null,8,["description"])):(p(),$("div",Nt,[n("table",Ot,[n("thead",null,[n("tr",null,[n("th",Rt,o(e.$t("ProductName")),1),n("th",Et,o(e.$t("Quantity")),1),n("th",Ut,o(e.$t("Total")),1)])]),(p(!0),$(V,null,j(z.value,l=>(p(),$("tbody",{key:l.category_id??"none"},[n("tr",Yt,[n("th",Ft,o(G(l)),1)]),(p(!0),$(V,null,j(l.items,w=>(p(),$("tr",{key:(l.category_id??0)+"-"+w.product_id+"-"+w.product_name},[n("td",null,[v(o(w.product_name)+" ",1),w.product_code?(p(),P(D,{key:0,type:"secondary",style:{display:"block","font-size":"12px"}},{default:i(()=>[v(o(w.product_code),1)]),_:2},1024)):lt("",!0)]),n("td",At,[v(o(T(w.quantity))+" ",1),s(D,{type:"secondary",style:{"font-size":"12px"}},{default:i(()=>[v(o(w.unit),1)]),_:2},1024)]),n("td",Gt,o(d(q)(w.total)),1)]))),128)),n("tr",Bt,[n("td",Mt,o(d(a)("Sub_Total","Sub Total")),1),n("td",Vt,o(T(l.sub_total_quantity)),1),n("td",Qt,o(d(q)(l.sub_total)),1)])]))),128)),n("tfoot",null,[n("tr",Lt,[n("td",jt,o(d(a)("Grand_Total","Grand Total")),1),n("td",Ht,o(T(_.value.quantity)),1),n("td",Kt,o(d(q)(_.value.total)),1)])])])]))]),_:1},8,["spinning"])]),_:1})])}}},ie=ut(Wt,[["__scopeId","data-v-3ac3e1ea"]]);export{ie as default};
