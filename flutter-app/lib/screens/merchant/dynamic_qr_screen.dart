import 'dart:async';
import '../../services/nfc_bridge.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:share_plus/share_plus.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Encaisser (§3.2.2, §6.2) : le marchand ou le caissier saisit le montant,
/// un QR à usage unique signé est généré (expiration courte), le paiement est
/// suivi en temps réel avec notification sonore. Mode « lien de paiement »
/// pour la vente à distance (envoi par SMS / partage).
class DynamicQrScreen extends StatefulWidget {
  final bool paymentLink;
  const DynamicQrScreen({super.key, this.paymentLink = false});

  @override
  State<DynamicQrScreen> createState() => _DynamicQrScreenState();
}

class _DynamicQrScreenState extends State<DynamicQrScreen> {
  final _service = MerchantToolsService();
  final _amount = TextEditingController();
  final _desc = TextEditingController();
  final _phone = FpPhoneController();
  Map<String, dynamic>? _req;
  Timer? _poll;
  Timer? _tick;
  int _left = 0;
  bool _busy = false;

  @override
  void dispose() {
    _poll?.cancel();
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _create() async {
    final amount = int.tryParse(_amount.text.replaceAll(' ', ''));
    if (amount == null || amount <= 0) return;
    setState(() => _busy = true);
    try {
      final r = await _service.createRequest(
        amount: amount,
        kind: widget.paymentLink ? 'payment_link' : 'dynamic_qr',
        description: _desc.text.trim(),
        customerPhone: widget.paymentLink && _phone.international.isNotEmpty ? _phone.international : null,
      );
      if (!mounted) return;
      setState(() {
        _req = r;
        _left = fpInt(r['seconds_left']);
      });
      if (widget.paymentLink) {
        await Share.share('Payer ${fpMoney(amount, '${r['currency'] ?? 'XAF'}')} à ${r['merchant']?['name'] ?? ''} avec FlashPay : ${r['link']}');
      }
      _startPolling();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startPolling() {
    _poll?.cancel();
    _tick?.cancel();
    _tick = Timer.periodic(Duration(seconds: 1), (_) {
      if (mounted && _left > 0) setState(() => _left--);
    });
    _poll = Timer.periodic(Duration(seconds: 2), (_) async {
      final token = _req?['token'];
      if (token == null) return;
      try {
        final r = await _service.request('$token');
        if (!mounted) return;
        setState(() => _req = r);
        if (r['status'] != 'pending') {
          _poll?.cancel();
          _tick?.cancel();
          if (r['status'] == 'paid') {
            // Notification sonore à chaque paiement reçu (§4.6.3, esprit WeChat Pay)
            SystemSound.play(SystemSoundType.alert);
            HapticFeedback.heavyImpact();
          }
        }
      } catch (_) {}
    });
  }

  Future<void> _cancel() async {
    final token = _req?['token'];
    if (token != null) {
      try {
        await _service.cancelRequest('$token');
      } catch (_) {}
    }
    _poll?.cancel();
    _tick?.cancel();
    setState(() => _req = null);
  }

  @override
  Widget build(BuildContext context) {
    final r = _req;
    final status = '${r?['status'] ?? ''}';
    return Scaffold(
      appBar: AppBar(title: Text(widget.paymentLink ? 'Lien de paiement' : 'Encaisser (QR dynamique)')),
      body: ListView(padding: EdgeInsets.all(24), children: [
        if (r == null) ...[
          TextField(
            controller: _amount,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 36, fontWeight: FontWeight.w900),
            decoration: InputDecoration(hintText: '0', suffixText: 'XAF'),
          ),
          SizedBox(height: 12),
          TextField(controller: _desc, decoration: InputDecoration(labelText: tr('Description (facultatif)'), prefixIcon: Icon(Icons.notes))),
          if (widget.paymentLink) ...[
            SizedBox(height: 12),
            FpPhoneField(label: tr('Numéro du client (envoi par SMS / notification)'), controller: _phone),
          ],
          SizedBox(height: 20),
          ElevatedButton.icon(
            onPressed: _busy ? null : _create,
            icon: Icon(widget.paymentLink ? Icons.link : Icons.qr_code_2),
            label: Text(widget.paymentLink ? 'Créer et partager le lien' : 'Générer le QR'),
          ),
        ] else if (status == 'paid') ...[
          Icon(Icons.check_circle, color: FpColors.success, size: 96),
          SizedBox(height: 12),
          Text(tr('Paiement reçu'), textAlign: TextAlign.center, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w900)),
          Text(fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}'), textAlign: TextAlign.center, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900, color: FpColors.success)),
          if (r['payer'] != null) Text('Payé par ${r['payer']}', textAlign: TextAlign.center),
          SizedBox(height: 24),
          ElevatedButton(onPressed: () => setState(() { _req = null; _amount.clear(); _desc.clear(); }), child: Text(tr('Nouvel encaissement'))),
        ] else ...[
          Text(fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}'), textAlign: TextAlign.center, style: TextStyle(fontSize: 32, fontWeight: FontWeight.w900)),
          if ((r['description'] ?? '').toString().isNotEmpty) Text('${r['description']}', textAlign: TextAlign.center),
          SizedBox(height: 16),
          Center(
            child: Container(
              padding: EdgeInsets.all(16),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
              child: FpNfcBeacon(
                payload: status == 'pending' ? '${r['qr_payload']}' : null,
                child: QrImageView(data: widget.paymentLink ? '${r['link']}' : '${r['qr_payload']}', size: 240),
              ),
            ),
          ),
          SizedBox(height: 12),
          if (status == 'pending')
            Text(widget.paymentLink ? 'En attente du paiement du client…' : 'Le client scanne ce QR · expire dans $_left s',
                textAlign: TextAlign.center, style: TextStyle(color: Colors.black54))
          else
            FpBanner(status == 'expired' ? 'QR expiré sans paiement.' : 'Demande annulée.', icon: Icons.timer_off),
          SizedBox(height: 8),
          if (status == 'pending') LinearProgressIndicator(),
          SizedBox(height: 16),
          if (widget.paymentLink && status == 'pending')
            OutlinedButton.icon(onPressed: () => Share.share('Payer avec FlashPay : ${r['link']}'), icon: Icon(Icons.share), label: Text(tr('Partager à nouveau'))),
          TextButton(onPressed: _cancel, child: Text(status == 'pending' ? 'Annuler' : 'Nouveau montant')),
        ],
      ]),
    );
  }
}
