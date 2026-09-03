<style>
:root{
    --oktane-navy:#0D1117;
    --oktane-deep-blue:#123A6D;
    --oktane-orange:#FF7A00;
    --oktane-orange-dark:#E64000;
    --oktane-text:#334155;
    --oktane-muted:#6B7280;
    --oktane-border:#E5E7EB;
    --oktane-canvas:#F7F8FA;
    --oktane-surface:#FFFFFF;
    --oktane-success:#16A34A;
    --oktane-success-bg:#DCFCE7;
    --oktane-success-text:#166534;
    --oktane-warning:#F59E0B;
    --oktane-warning-bg:#FEF3C7;
    --oktane-warning-text:#92400E;
    --oktane-danger:#DC2626;
    --oktane-danger-bg:#FEE2E2;
    --oktane-danger-text:#991B1B;
    --oktane-info:#2563EB;
    --oktane-info-bg:#DBEAFE;
    --oktane-info-text:#1E40AF;
    --radius-sm:8px;--radius-md:12px;--radius-lg:16px;
    --space:4px;
    --shadow-card:0 1px 2px rgba(13,17,23,.06);
    --shadow-modal:0 25px 80px rgba(13,17,23,.18);
    --font-ui:Inter,ui-sans-serif,system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--oktane-canvas);color:var(--oktane-text);font-family:var(--font-ui);-webkit-font-smoothing:antialiased}
a{color:inherit}
::selection{background:rgba(255,122,0,.22)}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:99px}
::-webkit-scrollbar-track{background:transparent}

/* Shared shell: sidebar + main */
.layout{min-height:100vh;display:grid;grid-template-columns:248px 1fr}
.sidebar{position:sticky;top:0;height:100vh;padding:24px 16px;border-right:1px solid var(--oktane-border);background:var(--oktane-deep-blue);color:#EAF1FB}
.brand{display:flex;align-items:center;gap:10px;padding:2px 8px 26px}
.brand img{display:block;height:26px;width:auto}
.brand strong{display:block;font-size:13px;color:#fff;font-weight:650}
.brand small{display:block;color:#9FB6D8;font-size:9px;letter-spacing:.09em;text-transform:uppercase;margin-top:2px}
.nav-label{padding:0 10px;margin:18px 0 7px;color:#7C93BE;font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:650}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);color:#C4D3EC;text-decoration:none;font-size:13px;font-weight:550;border-left:2px solid transparent}
.nav-item svg{width:16px;height:16px;flex:0 0 16px;opacity:.9}
.nav-item.active{background:rgba(255,255,255,.1);color:#fff;border-left-color:var(--oktane-orange)}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.06)}
.sidebar-foot{position:absolute;left:16px;right:16px;bottom:18px}
.who{padding:11px;border:1px solid rgba(255,255,255,.14);border-radius:var(--radius-sm);margin-bottom:9px;color:#B9CBE9;font-size:12px}
.logout,.queue-open{width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,.16);border-radius:var(--radius-sm);background:rgba(255,255,255,.04);color:#D9E4F6;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:550;white-space:nowrap}
.logout:hover,.queue-open:hover{background:rgba(255,255,255,.09);color:#fff}

.main{min-width:0;padding:32px clamp(20px,4vw,56px) 60px}
h1{margin:0;font-size:26px;letter-spacing:-.03em;color:var(--oktane-navy);font-weight:700}
.subtitle{margin:6px 0 0;color:var(--oktane-muted);font-size:13px}
.top{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:26px}
.actions{display:flex;gap:10px}
.section{margin-top:26px}
.section-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:12px}
.section h2{margin:0;font-size:16px;color:var(--oktane-navy)}
.section-head p{margin:4px 0 0;color:var(--oktane-muted);font-size:12px}

/* Buttons */
.button{padding:10px 14px;border:1px solid var(--oktane-border);border-radius:var(--radius-sm);background:#fff;color:var(--oktane-navy);text-decoration:none;font-weight:650;font-size:12px;cursor:pointer;font-family:inherit;transition:border-color .15s,background .15s}
.button:hover{border-color:#CBD5E1;background:#F8FAFC}
.button.primary{border-color:var(--oktane-navy);background:var(--oktane-navy);color:#fff}
.button.primary:hover{background:#1a2433}
.button:disabled{opacity:.5;cursor:default}
.button.icon{width:38px;height:38px;padding:0;display:grid;place-items:center;border-radius:50%;font-size:17px;line-height:1}
.button.icon.loading{animation:spin .8s linear infinite}
.button:not(.icon).loading{position:relative;overflow:hidden;border-color:var(--oktane-orange);box-shadow:0 0 0 1px rgba(255,122,0,.25),0 0 18px rgba(255,122,0,.16)}
.button:not(.icon).loading::after{content:"";position:absolute;inset:0;transform:translateX(-110%);background:linear-gradient(100deg,transparent,#ffffff55,transparent);animation:updateShimmer 1.1s linear infinite}
.filter.loading{cursor:wait;opacity:.8;border-color:var(--oktane-orange)}
@keyframes updateShimmer{to{transform:translateX(110%)}}
@keyframes spin{to{transform:rotate(360deg)}}

/* Forms */
.search,.filter,input[type=text],input[type=url],input[type=password],input[type=number]{padding:10px 12px;border:1px solid var(--oktane-border);border-radius:8px;background:#fff;color:var(--oktane-text);outline:none;font:inherit}
.search:focus,.filter:focus,input:focus{border-color:var(--oktane-orange);box-shadow:0 0 0 3px rgba(255,122,0,.16)}

/* Status / badges */
.status{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--oktane-muted)}
.dot{width:7px;height:7px;border-radius:50%;background:var(--oktane-success)}
.offline .dot{background:var(--oktane-danger)}
.pill{display:inline-block;padding:4px 9px;border-radius:999px;background:var(--oktane-success-bg);color:var(--oktane-success-text);font-size:10px;font-weight:650}
.pill.off{background:var(--oktane-warning-bg);color:var(--oktane-warning-text)}

/* Flash / toast */
.flash{margin-bottom:14px;padding:11px 14px;border:1px solid #BBF7D0;border-radius:10px;background:var(--oktane-success-bg);color:var(--oktane-success-text);font-size:12px}
.toast{position:fixed;right:22px;bottom:22px;padding:12px 15px;border:1px solid var(--oktane-border);border-radius:10px;background:#fff;box-shadow:var(--shadow-modal);font-size:12px;color:var(--oktane-navy);opacity:0;transform:translateY(10px);pointer-events:none;transition:.2s}
.toast.show{opacity:1;transform:none}

/* Modal */
.modal-overlay{position:fixed;inset:0;z-index:30;display:grid;place-items:center;padding:18px;background:rgba(13,17,23,0);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,background-color .2s ease,visibility 0s linear .2s}
.modal-overlay.open{background:rgba(13,17,23,.45);opacity:1;visibility:visible;pointer-events:auto;transition-delay:0s}
.modal,.confirm-box{width:min(470px,100%);padding:22px;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-modal);opacity:0;transform:translateY(14px) scale(.97);transition:opacity .2s ease,transform .2s cubic-bezier(.2,.8,.2,1)}
.modal-overlay.open .modal,.confirm-overlay.open .confirm-box{opacity:1;transform:translateY(0) scale(1)}
.modal-head{display:flex;align-items:flex-start;justify-content:space-between}
.modal h2,.confirm-box h2{margin:0;font-size:17px;color:var(--oktane-navy)}
.modal p,.confirm-box p{color:var(--oktane-muted);font-size:12px;line-height:1.6}
.modal-close{border:0;background:transparent;color:var(--oktane-muted);font-size:22px;cursor:pointer;line-height:1}
.modal label{display:block;margin-top:12px;color:var(--oktane-text);font-size:11px;font-weight:650}
.modal input{display:block;width:100%;margin-top:6px}
.form-error{margin-top:7px;color:var(--oktane-danger);font-size:11px}

/* Tables */
table{width:100%;border-collapse:collapse}
th,td{padding:12px 15px;text-align:left;border-bottom:1px solid var(--oktane-border);font-size:12px}
th{color:var(--oktane-muted);font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:650}
tbody tr:hover{background:var(--oktane-canvas)}
.muted{color:var(--oktane-muted)}

@media(max-width:760px){
    .layout{display:block}
    .sidebar{position:static;width:100%;height:auto;border-right:0;border-bottom:1px solid var(--oktane-border)}
    .sidebar .nav-label,.sidebar .nav-item:not(.active),.sidebar-foot{display:none}
    .brand{padding-bottom:12px}
    .main{padding:24px 16px 50px}
    .top{display:block}
    .actions{margin-top:16px}
}
</style>
