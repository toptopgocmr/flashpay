import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../models/payment_method.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import 'card_deposit_screen.dart';
import 'mobile_money_screen.dart';
import 'nfc_pay_screen.dart';
import 'pay_code_screen.dart';
import 'qr_scan_screen.dart';
import 'voucher_screen.dart';
import '../../l10n/l10n.dart';

enum FundsOperation { deposit, withdraw, pay }

/// Recharger / Retirer / Payer : l'utilisateur choisit un pays et FlashPay
/// lui propose les moyens disponibles dans ce pays (mobile money de chaque
/// opérateur, agent, cash pickup, GAB, QR…). Les moyens pas encore ouverts
/// s'affichent grisés « Bientôt disponible ».
class FundsScreen extends StatefulWidget {
  final FundsOperation operation;
  const FundsScreen({super.key, required this.operation});

  @override
  State<FundsScreen> createState() => _FundsScreenState();
}

class _FundsScreenState extends State<FundsScreen> {
  final _service = PaymentService();
  List<FpCountry>? _countries;
  String? _iso;
  FpMethods? _methods;
  String? _error;
  bool _loading = true;

  String get _op => switch (widget.operation) {
        FundsOperation.deposit => 'deposit',
        FundsOperation.withdraw => 'withdraw',
        FundsOperation.pay => 'pay',
      };

  String get _title => switch (widget.operation) {
        FundsOperation.deposit => 'Recharger mon compte',
        FundsOperation.withdraw => 'Retirer de l\'argent',
        FundsOperation.pay => 'Payer',
      };

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    try {
      final countries = await _service.countries();
      if (!mounted) return;
      final walletCountry = context.read<SessionProvider>().user?.wallet?.country;
      setState(() {
        _countries = countries;
        _iso = countries.any((c) => c.iso == walletCountry) ? walletCountry : (countries.isNotEmpty ? countries.first.iso : null);
      });
      await _load();
    } catch (e) {
      if (mounted) setState(() { _error = apiErrorMessage(e); _loading = false; });
    }
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final m = await _service.methods(operation: _op, country: _iso);
      if (mounted) setState(() { _methods = m; _loading = false; });
    } catch (e) {
      if (mounted) setState(() { _error = apiErrorMessage(e); _loading = false; });
    }
  }

  Future<void> _pickCountry() async {
    final countries = _countries ?? [];
    final iso = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => SafeArea(
        child: SizedBox(
          height: MediaQuery.of(ctx).size.height * 0.7,
          child: Column(children: [
            Padding(
              padding: EdgeInsets.all(16),
              child: Text(tr('Choisir un pays'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            ),
            Expanded(
              child: ListView(
                children: countries
                    .map((c) => ListTile(
                          leading: Text(c.flag, style: TextStyle(fontSize: 24)),
                          title: Text(c.name),
                          subtitle: Text('${c.currency} · ${c.operators.map((o) => o.label).toSet().join(', ')}',
                              maxLines: 1, overflow: TextOverflow.ellipsis),
                          trailing: c.iso == _iso ? Icon(Icons.check_circle, color: FpColors.teal) : null,
                          onTap: () => Navigator.pop(ctx, c.iso),
                        ))
                    .toList(),
              ),
            ),
          ]),
        ),
      ),
    );
    if (iso != null && iso != _iso) {
      setState(() => _iso = iso);
      _load();
    }
  }

  void _open(FpMethod m) {
    final iso = _iso ?? 'CG';
    final Widget? next = switch ((widget.operation, m.key)) {
      (FundsOperation.deposit, 'mobile_money') => MobileMoneyScreen(mode: MobileMoneyMode.deposit, initialIso: iso),
      (FundsOperation.deposit, 'card') => CardDepositScreen(),
      (FundsOperation.pay, 'nfc') => NfcPayScreen(),
      (FundsOperation.deposit, 'agent_qr') => PayCodeScreen(purpose: PayCodePurpose.deposit),
      (FundsOperation.withdraw, 'mobile_money') => MobileMoneyScreen(mode: MobileMoneyMode.withdraw, initialIso: iso),
      (FundsOperation.withdraw, 'cash_pickup') => VoucherScreen(channel: 'cash_pickup', country: iso, countryName: _methods?.name ?? iso),
      (FundsOperation.withdraw, 'atm') => VoucherScreen(channel: 'atm', country: iso, countryName: _methods?.name ?? iso),
      (FundsOperation.pay, 'scan_qr') => QrScanScreen(),
      (FundsOperation.pay, 'mobile_money') => QrScanScreen(),
      (FundsOperation.pay, 'pay_code') => PayCodeScreen(purpose: PayCodePurpose.pay),
      _ => null,
    };
    if (next != null) Navigator.push(context, MaterialPageRoute(builder: (_) => next));
  }

  IconData _icon(String key) => switch (key) {
        'phone' => Icons.phone_android_rounded,
        'store' => Icons.storefront_rounded,
        'cash' => Icons.payments_rounded,
        'atm' => Icons.local_atm_rounded,
        'scan' => Icons.qr_code_scanner_rounded,
        'qr' => Icons.qr_code_2_rounded,
        'card' => Icons.credit_card_rounded,
        'nfc' => Icons.contactless_rounded,
        'bank' => Icons.account_balance_rounded,
        'wallet' => Icons.account_balance_wallet_rounded,
        _ => Icons.circle_outlined,
      };

  @override
  Widget build(BuildContext context) {
    final m = _methods;
    final country = _countries?.where((c) => c.iso == _iso).firstOrNull;

    return Scaffold(
      appBar: AppBar(title: Text(_title)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: EdgeInsets.all(20),
          children: [
            // Sélecteur de pays
            Material(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: _countries == null ? null : _pickCountry,
                child: Padding(
                  padding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  child: Row(children: [
                    Text(country?.flag ?? '🌍', style: TextStyle(fontSize: 26)),
                    SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('Pays'), style: TextStyle(fontSize: 12, color: Colors.black54)),
                        Text(country != null ? '${country.name} (${country.currency})' : 'Choisir un pays',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                      ]),
                    ),
                    Text(tr('Changer'), style: TextStyle(color: FpColors.teal, fontWeight: FontWeight.w700)),
                  ]),
                ),
              ),
            ),
            SizedBox(height: 20),
            if (_loading) Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
            if (_error != null) Text(_error!, style: TextStyle(color: FpColors.danger)),
            if (!_loading && m != null) ...[
              Text(
                switch (widget.operation) {
                  FundsOperation.deposit => 'Comment voulez-vous recharger ?',
                  FundsOperation.withdraw => 'Comment voulez-vous retirer ?',
                  FundsOperation.pay => 'Comment voulez-vous payer ?',
                },
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
              ),
              SizedBox(height: 12),
              ...m.methods.map((x) => _MethodTile(method: x, icon: _icon(x.icon), onTap: x.available ? () => _open(x) : null)),
            ],
          ],
        ),
      ),
    );
  }
}

class _MethodTile extends StatelessWidget {
  final FpMethod method;
  final IconData icon;
  final VoidCallback? onTap;
  const _MethodTile({required this.method, required this.icon, this.onTap});

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Card(
        margin: EdgeInsets.only(bottom: 10),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Padding(
            padding: EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: enabled ? FpColors.soft : Colors.black12, borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: FpColors.navy),
              ),
              SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(method.label, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  SizedBox(height: 2),
                  Text(method.description, style: TextStyle(fontSize: 12.5, color: Colors.black54)),
                  if (method.operators.isNotEmpty) ...[
                    SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: method.operators
                          .map((o) => Container(
                                padding: EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(color: FpColors.background, borderRadius: BorderRadius.circular(20)),
                                child: Text(o, style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)),
                              ))
                          .toList(),
                    ),
                  ],
                  if (!enabled && method.reason != null) ...[
                    SizedBox(height: 6),
                    Text(method.reason!, style: TextStyle(fontSize: 12, fontStyle: FontStyle.italic)),
                  ],
                ]),
              ),
              if (enabled) Icon(Icons.chevron_right, color: Colors.black38),
            ]),
          ),
        ),
      ),
    );
  }
}
