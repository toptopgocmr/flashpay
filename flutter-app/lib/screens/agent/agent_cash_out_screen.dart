import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../../config/theme.dart';
import '../../services/agent_service.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import 'agent_result_screen.dart';
import '../../l10n/l10n.dart';

/// Retrait cash : le client présente son code de retrait (10 chiffres ou QR).
/// L'agent vérifie le montant et le bénéficiaire, remet les espèces, puis
/// confirme : son float est reconstitué + commission.
class AgentCashOutScreen extends StatefulWidget {
  /// Code de retrait déjà lu (NFC depuis l'accueil agent) : vérifié à l'ouverture.
  final String? initialRaw;
  const AgentCashOutScreen({super.key, this.initialRaw});

  @override
  State<AgentCashOutScreen> createState() => _AgentCashOutScreenState();
}

class _AgentCashOutScreenState extends State<AgentCashOutScreen> {
  final _service = AgentService();
  final _codeCtrl = TextEditingController();
  MobileScannerController? _scanner;
  bool _scan = false;
  bool _busy = false;
  String? _error;
  String? _code;
  Map<String, dynamic>? _voucher;

  @override
  void initState() {
    super.initState();
    final raw = widget.initialRaw;
    if (raw != null) {
      _codeCtrl.text = fpExtractCode(raw, host: 'cashout', length: 10) ?? '';
      WidgetsBinding.instance.addPostFrameCallback((_) => _check(raw));
    }
  }

  @override
  void dispose() {
    _scanner?.dispose();
    super.dispose();
  }

  Future<void> _check(String raw) async {
    final code = fpExtractCode(raw, host: 'cashout', length: 10);
    if (code == null) {
      setState(() => _error = 'Code de retrait invalide (10 chiffres).');
      return;
    }
    setState(() { _busy = true; _error = null; _scan = false; });
    await _scanner?.stop();
    try {
      final v = await _service.voucher(code);
      if (mounted) setState(() { _voucher = v; _code = code; });
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _redeem() async {
    setState(() { _busy = true; _error = null; });
    try {
      final r = await _service.redeem(_code!);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(
        builder: (_) => AgentResultScreen(
          success: true,
          title: tr('Retrait effectué'),
          amount: fpMoney((r['amount'] ?? 0) as num, (r['currency'] ?? 'XAF') as String),
          lines: ['Remis à ${r['beneficiary_name'] ?? 'le client'}', 'Votre float a été reconstitué (+ commission).'],
        ),
      ));
    } catch (e) {
      if (mounted) setState(() { _error = apiErrorMessage(e); _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final v = _voucher;
    if (_scan && _scanner == null) _scanner = MobileScannerController();

    return Scaffold(
      appBar: AppBar(title: Text(tr('Retrait cash'))),
      body: v != null
          ? ListView(padding: EdgeInsets.all(20), children: [
              Container(
                padding: EdgeInsets.all(20),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
                child: Column(children: [
                  Text(tr('Montant à remettre'), style: TextStyle(color: Colors.black54)),
                  SizedBox(height: 4),
                  Text(fpMoney((v['amount'] ?? 0) as num, (v['currency'] ?? 'XAF') as String),
                      style: TextStyle(fontSize: 34, fontWeight: FontWeight.w900, color: FpColors.navy)),
                  Divider(height: 28),
                  _row('Bénéficiaire', '${v['beneficiary_name'] ?? '—'}'),
                  _row('Code', _code ?? ''),
                  _row('Expire le', '${DateTime.tryParse('${v['expires_at']}')?.toLocal().toString().substring(0, 16) ?? '—'}'),
                ]),
              ),
              SizedBox(height: 14),
              Container(
                padding: EdgeInsets.all(12),
                decoration: BoxDecoration(color: Color(0xFFE3F3FF), borderRadius: BorderRadius.circular(12)),
                child: Text(tr('Vérifiez la pièce d\'identité : le nom doit correspondre au bénéficiaire. Remettez les espèces AVANT de confirmer.'),
                    style: TextStyle(fontSize: 13, color: Color(0xFFA16207))),
              ),
              if (_error != null) Padding(padding: EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
              SizedBox(height: 20),
              ElevatedButton(onPressed: _busy ? null : _redeem, child: Text(_busy ? 'Traitement…' : 'J\'ai remis les espèces')),
              TextButton(onPressed: _busy ? null : () => setState(() { _voucher = null; _code = null; }), child: Text(tr('Annuler'))),
            ])
          : Column(children: [
              Padding(
                padding: EdgeInsets.all(20),
                child: Column(children: [
                  TextField(
                    controller: _codeCtrl,
                    keyboardType: TextInputType.number,
                    style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: 2),
                    decoration: InputDecoration(labelText: tr('Code de retrait du client'), hintText: tr('10 chiffres')),
                  ),
                  SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: ElevatedButton(onPressed: _busy ? null : () => _check(_codeCtrl.text), child: Text(_busy ? 'Vérification…' : 'Vérifier'))),
                    SizedBox(width: 10),
                    OutlinedButton.icon(onPressed: _busy ? null : () => setState(() => _scan = !_scan), icon: Icon(Icons.qr_code_scanner), label: Text(_scan ? 'Fermer' : 'Scanner')),
                    SizedBox(width: 8),
                    IconButton.outlined(
                      tooltip: tr('Lire par NFC'),
                      onPressed: _busy
                          ? null
                          : () async {
                              final raw = await FpNfc.readSheet(context, hint: 'Le client affiche son code de retrait et approche son téléphone.');
                              if (raw != null) _check(raw);
                            },
                      icon: Icon(Icons.nfc_rounded),
                    ),
                  ]),
                  if (_error != null) Padding(padding: EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
                ]),
              ),
              if (_scan)
                Expanded(
                  child: Stack(children: [
                    MobileScanner(controller: _scanner, onDetect: (c) {
                      final raw = c.barcodes.isEmpty ? null : c.barcodes.first.rawValue;
                      if (raw != null && !_busy) _check(raw);
                    }),
                    Center(child: Container(width: 220, height: 220, decoration: BoxDecoration(border: Border.all(color: FpColors.orange, width: 4), borderRadius: BorderRadius.circular(24)))),
                  ]),
                ),
            ]),
    );
  }

  Widget _row(String k, String v) => Padding(
        padding: EdgeInsets.symmetric(vertical: 4),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(k, style: TextStyle(color: Colors.black54)),
          Flexible(child: Text(v, textAlign: TextAlign.right, style: TextStyle(fontWeight: FontWeight.w700))),
        ]),
      );
}
