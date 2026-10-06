<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #eef1f6; font: 15px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; }
  .wrap { max-width: 460px; margin: 20px auto; padding: 0 14px; }
  .card { background: #fff; border-radius: 18px; box-shadow: 0 6px 24px rgba(15, 23, 42, .08); overflow: hidden; }
  .head { background: #1e3a8a; color: #fff; padding: 20px 22px; display: flex; justify-content: space-between; align-items: center; }
  .brand { font-weight: 800; font-size: 20px; letter-spacing: -.01em; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand span { color: #fca5a5; }
  .logo { width: 38px; height: 38px; background: #fff; border-radius: 10px; padding: 4px; object-fit: contain; }
  .head small { opacity: .8; }
  .hero { text-align: center; padding: 22px 22px 8px; }
  .status { display: inline-block; padding: 3px 12px; border-radius: 999px; font-weight: 700; font-size: 13px;  }
  .amount { font-size: 34px; font-weight: 800; margin: 10px 0 2px; letter-spacing: -.02em; }
  .type { color: #64748b; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0 4px; }
  td { padding: 9px 22px; border-top: 1px solid #eef1f6; vertical-align: top; }
  td:first-child { color: #64748b; width: 42%; }
  td:last-child { text-align: right; font-weight: 600; word-break: break-word; }
  .total td { font-size: 16px; }
  .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }
  .fail { margin: 0 22px 14px; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: #991b1b; font-size: 13px; }
  .foot { padding: 14px 22px 20px; color: #64748b; font-size: 12px; text-align: center; border-top: 1px dashed #cbd5e1; }
  .actions { text-align: center; margin: 16px 0 30px; }
  .actions button { border: 0; background: #1e3a8a; color: #fff; font-weight: 700; padding: 11px 22px; border-radius: 999px; font-size: 15px; cursor: pointer; }
  @media print { body { background: #fff; } .actions { display: none; } .card { box-shadow: none; } .wrap { margin: 0 auto; } }
  .status.s-successful { color: #037f0c; background: #037f0c1a; } .status.s-failed { color: #d91515; background: #d915151a; }
  .status.s-reversed { color: #b45309; background: #b453091a; } .status.s-processing { color: #0972d3; background: #0972d31a; }
  .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
  .actions button.alt { background: #fff; color: #1e3a8a; border: 1.5px solid #1e3a8a; }
  .agent { margin: 0 22px 12px; padding: 8px 12px; border-radius: 10px; background: #eff6ff; color: #1e3a8a; font-size: 13px; text-align: center; }
  .sheet { break-inside: avoid; page-break-inside: avoid; }
  .sheet + .sheet { margin-top: 18px; }
  @media print { .sheet + .sheet { break-before: page; page-break-before: always; margin-top: 0; } }
  /* Format ticket (imprimante thermique 58/80 mm) */
  body.ticket { background: #fff; font-size: 12px; }
  body.ticket .wrap { max-width: 300px; margin: 0 auto; padding: 0 4px; }
  body.ticket .card { box-shadow: none; border-radius: 0; }
  body.ticket .head { background: #fff; color: #000; border-bottom: 1px dashed #000; padding: 8px 4px; }
  body.ticket .brand span { color: #000; } body.ticket .logo { width: 26px; height: 26px; padding: 0; }
  body.ticket .amount { font-size: 22px; } body.ticket td { padding: 4px; border-top: 1px dotted #999; }
  body.ticket .status { background: none; color: #000; border: 1px solid #000; }
  body.ticket .foot, body.ticket .agent { padding: 8px 4px; margin: 0; background: none; color: #000; }
  @media print { body.ticket .sheet + .sheet { break-before: auto; page-break-before: auto; border-top: 1px dashed #000; margin-top: 10px; padding-top: 10px; } @page { margin: 4mm; } }
</style>
