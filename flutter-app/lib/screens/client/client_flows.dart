import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/linked_sources.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import 'card_deposit_screen.dart';
import 'funds_screen.dart';
import 'mobile_money_screen.dart';
import 'nfc_pay_screen.dart';
import 'pay_code_screen.dart';
import 'pay_merchant_screen.dart';
import 'qr_pay_screen.dart';
import 'qr_scan_screen.dart';
import 'send_money_screen.dart';
import 'voucher_screen.dart';
import 'money_request_screen.dart';
import '../../services/nfc_bridge.dart';
import '../../l10n/l10n.dart';

/// Parcours client de la maquette v2 : l'utilisateur choisit d'abord le
/// TYPE d'opération (A → B), puis la façon de désigner le destinataire
/// (numéro, scanner, NFC, code). « Continuer » ouvre l'écran fonctionnel
/// existant (devis, frais, PIN, suivi) déjà pré-réglé sur ce choix.

void _push(BuildContext context, Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));

/// NFC entre deux téléphones : lit le code FlashPay de l'autre téléphone
/// (HCE) ou d'un tag, via [FpNfc.readSheet].
Future<String?> _readNfc(BuildContext context, String hint) => FpNfc.readSheet(context, hint: hint);

// ===========================================================================
// Recharger mon wallet
// ===========================================================================

enum _RechargeTab { manual, qr, nfc }

enum _RechargeSource { mobile, card, agent }

class RechargeWalletScreen extends StatefulWidget {
  const RechargeWalletScreen({super.key});

  @override
  State<RechargeWalletScreen> createState() => _RechargeWalletScreenState();
}

class _RechargeWalletScreenState extends State<RechargeWalletScreen> {
  _RechargeTab _tab = _RechargeTab.manual;
  _RechargeSource _source = _RechargeSource.mobile;

  void _continue() {
    switch (_tab) {
      case _RechargeTab.manual:
        final iso = context.read<SessionProvider>().user?.wallet?.country ?? 'CG';
        _push(context, switch (_source) {
          _RechargeSource.mobile => MobileMoneyScreen(mode: MobileMoneyMode.deposit, initialIso: iso),
          _RechargeSource.card => CardDepositScreen(),
          _RechargeSource.agent => PayCodeScreen(purpose: PayCodePurpose.deposit),
        });
      case _RechargeTab.qr:
        // Scanner le QR d'un ami ouvre directement « Demander de l'argent »
        _push(context, QrScanScreen(request: true, title: tr('Scanner un ami')));
      case _RechargeTab.nfc:
        _push(context, NfcPayScreen(request: true));
    }
  }

  Widget _qrCard(IconData icon, FpTone tone, String title, String sub, VoidCallback onTap) => Expanded(
        child: Material(
          color: FpColors.background,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(18),
            child: Padding(
              padding: EdgeInsets.symmetric(vertical: 18, horizontal: 8),
              child: Column(children: [
                Icon(icon, size: 30, color: tone == FpTone.red ? FpColors.red : Color(0xFF3056D3)),
                SizedBox(height: 10),
                Text(tr(title), textAlign: TextAlign.center, style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w500)),
                SizedBox(height: 2),
                Text(tr(sub), textAlign: TextAlign.center, style: TextStyle(fontSize: 13.5, color: FpColors.muted)),
              ]),
            ),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final children = <Widget>[
      FpSegmented<_RechargeTab>(
        items: [(_RechargeTab.manual, 'Manuel'), (_RechargeTab.qr, 'QR code'), (_RechargeTab.nfc, 'NFC')],
        selected: _tab,
        onChanged: (t) => setState(() => _tab = t),
      ),
      SizedBox(height: 6),
    ];

    switch (_tab) {
      case _RechargeTab.manual:
        children.addAll([
          FpFlowLabel('Recharger depuis'),
          FpFlowTypeList<_RechargeSource>(
            options: [
              FpFlowType(_RechargeSource.mobile, FpNode.mobile, FpNode.wallet, 'Mon mobile money'),
              FpFlowType(_RechargeSource.card, FpNode.bank, FpNode.wallet, 'Carte / banque'),
              FpFlowType(_RechargeSource.agent, FpNode.cash, FpNode.wallet, 'Espèces chez un agent'),
            ],
            selected: _source,
            onSelect: (v) => setState(() => _source = v),
          ),
        ]);
      case _RechargeTab.qr:
        children.addAll([
          SizedBox(height: 12),
          Container(
            padding: EdgeInsets.all(16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22), border: Border.all(color: FpColors.navy.withOpacity(.4))),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(Icons.qr_code_2_rounded, color: Color(0xFF3056D3)),
                SizedBox(width: 12),
                Text(tr('QR code — deux usages'), style: TextStyle(fontSize: 16.5, fontWeight: FontWeight.w500)),
              ]),
              SizedBox(height: 14),
              Row(children: [
                _qrCard(Icons.center_focus_weak_rounded, FpTone.red, 'Scanner un ami', 'pour lui demander',
                    () => _push(context, QrScanScreen(request: true, title: tr('Scanner un ami')))),
                SizedBox(width: 12),
                _qrCard(Icons.qr_code_2_rounded, FpTone.blue, 'Montrer mon QR', 'pour qu\'un ami m\'envoie', () => _push(context, QrPayScreen())),
              ]),
            ]),
          ),
          SizedBox(height: 12),
          FpPromptCard(
            icon: Icons.storefront_outlined,
            tone: FpTone.red,
            title: tr('Espèces chez un agent'),
            subtitle: tr('Montrez votre code de dépôt : l\'agent le scanne'),
            onTap: () => _push(context, PayCodeScreen(purpose: PayCodePurpose.deposit)),
          ),
          FpPromptCard(
            icon: Icons.inbox_outlined,
            tone: FpTone.blue,
            title: tr('Mes demandes d\'argent'),
            subtitle: tr('Suivre les demandes envoyées et reçues'),
            onTap: () => _push(context, MoneyRequestsScreen(initialTab: 1)),
          ),
        ]);
      case _RechargeTab.nfc:
        children.addAll([
          SizedBox(height: 12),
          Container(
            padding: EdgeInsets.symmetric(horizontal: 18, vertical: 20),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: FpColors.line)),
            child: Row(children: [
              Icon(Icons.nfc_rounded, color: FpColors.red),
              SizedBox(width: 14),
              Expanded(child: Text(tr('Approcher le téléphone d\'un ami pour lui demander'), style: TextStyle(fontSize: 16, color: FpColors.ink))),
            ]),
          ),
          SizedBox(height: 12),
          FpPromptCard(
            icon: Icons.storefront_outlined,
            tone: FpTone.red,
            title: tr('Chez un agent'),
            subtitle: tr('Présentez votre code de dépôt : l\'agent approche son téléphone'),
            onTap: () => _push(context, PayCodeScreen(purpose: PayCodePurpose.deposit)),
          ),
        ]);
    }

    return FpFlowScaffold(
      title: tr('Recharger mon wallet'),
      bottom: FpButton('Continuer', red: true, onPressed: _continue),
      children: children,
    );
  }
}

// ===========================================================================
// Envoyer de l'argent
// ===========================================================================

enum SendType { walletToWallet, walletToMobile, mobileToMobile, mobileToWallet, cardToMobile, walletToBank, cardToBank, bankToWallet }

enum _Designate { number, scanner, nfc, code }

class SendFlowScreen extends StatefulWidget {
  const SendFlowScreen({super.key});

  @override
  State<SendFlowScreen> createState() => _SendFlowScreenState();
}

class _SendFlowScreenState extends State<SendFlowScreen> {
  SendType _type = SendType.walletToWallet;
  _Designate _how = _Designate.number;

  /// Banque → wallet = recharge de SON wallet : pas de destinataire à désigner.
  bool get _needsRecipient => _type != SendType.bankToWallet;

  void _continue() {
    if (_type == SendType.bankToWallet) {
      _push(context, FundsScreen(operation: FundsOperation.deposit));
      return;
    }
    final (source, deliver) = switch (_type) {
      SendType.walletToWallet => ('wallet', 'auto'),
      SendType.walletToMobile => ('wallet', 'mobile'),
      SendType.mobileToMobile => ('mobile', 'mobile'),
      SendType.mobileToWallet => ('mobile', 'auto'),
      SendType.cardToMobile => ('card', 'mobile'),
      SendType.cardToBank => ('card', 'bank'),
      SendType.walletToBank => ('wallet', 'bank'),
      SendType.bankToWallet => ('card', 'auto'),
    };
    switch (_how) {
      case _Designate.scanner:
        _push(context, QrScanScreen());
      case _Designate.nfc:
        _sendByNfc(source, deliver);
      case _Designate.number:
      case _Designate.code:
        _push(context, SendMoneyScreen(initialSource: source, initialDeliverTo: deliver));
    }
  }

  /// L'ami affiche « Code QR » ; on lit son numéro par NFC puis on pré-remplit l'envoi.
  Future<void> _sendByNfc(String source, String deliver) async {
    final raw = await _readNfc(context, 'Demandez à votre ami d\'ouvrir « Code QR » dans son application, puis placez les deux téléphones dos à dos.');
    if (raw == null || !mounted) return;
    final phone = FpNfc.phoneFromLink(raw);
    if (phone == null) {
      fpSnack(context, 'Ce téléphone n\'affiche pas de code « Mon QR » FlashPay.', error: true);
      return;
    }
    _push(context, SendMoneyScreen(initialPhone: phone, initialSource: source, initialDeliverTo: deliver));
  }

  @override
  Widget build(BuildContext context) {
    return FpFlowScaffold(
      title: tr('Envoyer de l\'argent'),
      bottom: FpButton('Continuer', red: true, onPressed: _continue),
      children: [
        FpFlowLabel('Type de transfert'),
        FpFlowTypeList<SendType>(
          options: [
            FpFlowType(SendType.walletToWallet, FpNode.wallet, FpNode.wallet, 'Wallet vers wallet'),
            FpFlowType(SendType.walletToMobile, FpNode.wallet, FpNode.mobile, 'Wallet vers mobile money'),
            FpFlowType(SendType.mobileToMobile, FpNode.mobile, FpNode.mobile, 'Mobile money vers mobile money'),
            FpFlowType(SendType.mobileToWallet, FpNode.mobile, FpNode.wallet, 'Mobile money vers wallet'),
            FpFlowType(SendType.cardToMobile, FpNode.card, FpNode.mobile, 'Carte vers mobile money'),
            FpFlowType(SendType.walletToBank, FpNode.wallet, FpNode.bank, 'Wallet vers banque'),
            FpFlowType(SendType.cardToBank, FpNode.card, FpNode.bank, 'Carte vers compte bancaire'),
            FpFlowType(SendType.bankToWallet, FpNode.bank, FpNode.wallet, 'Banque vers wallet'),
          ],
          selected: _type,
          onSelect: (v) => setState(() => _type = v),
        ),
        if (_needsRecipient) ...[
          FpFlowLabel('Sélectionner le destinataire'),
          FpMethodRow<_Designate>(
            methods: [
              FpPickMethod(_Designate.number, Icons.dialpad_rounded, 'Numéro', tone: FpTone.blue),
              FpPickMethod(_Designate.nfc, Icons.nfc_rounded, 'NFC'),
              FpPickMethod(_Designate.scanner, Icons.center_focus_weak_rounded, 'Scanner', tone: FpTone.blue),
            ],
            selected: _how,
            onSelect: (v) => setState(() => _how = v),
          ),
        ],
      ],
    );
  }
}

// ===========================================================================
// Retrait
// ===========================================================================

enum _WithdrawTo { cash, mobile, bank }

class WithdrawFlowScreen extends StatefulWidget {
  const WithdrawFlowScreen({super.key});

  @override
  State<WithdrawFlowScreen> createState() => _WithdrawFlowScreenState();
}

class _WithdrawFlowScreenState extends State<WithdrawFlowScreen> {
  _WithdrawTo _to = _WithdrawTo.cash;
  _Designate _how = _Designate.code;
  bool _busy = false;

  Future<void> _cashVoucher() async {
    final iso = context.read<SessionProvider>().user?.wallet?.country ?? 'CG';
    setState(() => _busy = true);
    String name = iso;
    try {
      final countries = await PaymentService().countries();
      name = countries.where((c) => c.iso == iso).map((c) => c.name).firstOrNull ?? iso;
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    if (mounted) _push(context, VoucherScreen(channel: 'cash_pickup', country: iso, countryName: name));
  }

  /// L'agent affiche « Mon code QR » ; on le lit par NFC puis on génère le code de retrait.
  Future<void> _withdrawByNfc() async {
    final raw = await _readNfc(context, 'Demandez à l\'agent d\'ouvrir « Mon code QR », puis placez les deux téléphones dos à dos.');
    if (raw == null || !mounted) return;
    final uri = Uri.tryParse(raw);
    if (uri == null || uri.scheme != 'flashpay' || uri.host != 'agent') {
      fpSnack(context, 'Ce téléphone n\'affiche pas le code d\'un agent FlashPay.', error: true);
      return;
    }
    _cashVoucher();
  }

  void _continue() {
    final iso = context.read<SessionProvider>().user?.wallet?.country ?? 'CG';
    switch (_to) {
      case _WithdrawTo.mobile:
        _push(context, MobileMoneyScreen(mode: MobileMoneyMode.withdraw, initialIso: iso));
      case _WithdrawTo.bank:
        _push(context, FundsScreen(operation: FundsOperation.withdraw));
      case _WithdrawTo.cash:
        switch (_how) {
          case _Designate.scanner:
            // Le QR de l'agent (flashpay://agent?a=AG…) ouvre le code de retrait
            _push(context, QrScanScreen());
          case _Designate.nfc:
            _withdrawByNfc();
          case _Designate.number:
          case _Designate.code:
            _cashVoucher();
        }
    }
  }

  @override
  Widget build(BuildContext context) {
    return FpFlowScaffold(
      title: tr('Retrait'),
      bottom: FpButton('Continuer', red: true, onPressed: _continue, loading: _busy),
      children: [
        FpFlowLabel('Retirer vers'),
        FpFlowTypeList<_WithdrawTo>(
          options: [
            FpFlowType(_WithdrawTo.cash, FpNode.wallet, FpNode.cash, 'Espèces chez un agent'),
            FpFlowType(_WithdrawTo.mobile, FpNode.wallet, FpNode.mobile, 'Mobile money'),
            FpFlowType(_WithdrawTo.bank, FpNode.wallet, FpNode.bank, 'Compte bancaire'),
          ],
          selected: _to,
          onSelect: (v) => setState(() => _to = v),
        ),
        if (_to == _WithdrawTo.cash) ...[
          FpFlowLabel('Désigner l\'agent'),
          FpMethodRow<_Designate>(
            methods: [
              FpPickMethod(_Designate.code, Icons.pin_outlined, 'Code de retrait', tone: FpTone.blue),
              FpPickMethod(_Designate.nfc, Icons.nfc_rounded, 'NFC'),
              FpPickMethod(_Designate.scanner, Icons.center_focus_weak_rounded, 'Scanner', tone: FpTone.blue),
            ],
            selected: _how,
            onSelect: (v) => setState(() => _how = v),
          ),
          Padding(
            padding: EdgeInsets.only(top: 12, left: 4, right: 4),
            child: Text(tr('Vous recevez un code de retrait à présenter à l\'agent avec votre pièce d\'identité.'),
                style: TextStyle(fontSize: 13, color: FpColors.muted)),
          ),
        ],
      ],
    );
  }
}

// ===========================================================================
// Payer un marchand
// ===========================================================================

enum _PayType { flashpay, mobileToMerchant, cardToMerchant, mobileMoney }

class PayFlowScreen extends StatefulWidget {
  const PayFlowScreen({super.key});

  @override
  State<PayFlowScreen> createState() => _PayFlowScreenState();
}

class _PayFlowScreenState extends State<PayFlowScreen> {
  _PayType _type = _PayType.flashpay;
  _Designate _how = _Designate.scanner;

  Future<void> _askCode() async {
    final ctrl = TextEditingController();
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Code marchand')),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          textCapitalization: TextCapitalization.characters,
          decoration: InputDecoration(hintText: tr('FPM-XXXXXXXXXXXX')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim().toUpperCase()), child: Text(tr('Continuer'))),
        ],
      ),
    );
    if (code != null && code.isNotEmpty && mounted) _push(context, PayMerchantScreen(merchantCode: code));
  }

  void _continue() {
    // Compte à débiter pour le marchand FlashPay : wallet, mobile money lié ou carte liée
    LinkedSources.preferredPaySource = switch (_type) {
      _PayType.mobileToMerchant => 'mobile',
      _PayType.cardToMerchant => 'card',
      _ => 'wallet',
    };
    if (_type == _PayType.mobileMoney && _how != _Designate.scanner) {
      _push(context, FundsScreen(operation: FundsOperation.pay));
      return;
    }
    switch (_how) {
      case _Designate.scanner:
        _push(context, QrScanScreen());
      case _Designate.nfc:
        _push(context, NfcPayScreen());
      case _Designate.code:
      case _Designate.number:
        _askCode();
    }
  }

  @override
  Widget build(BuildContext context) {
    return FpFlowScaffold(
      title: tr('Payer un marchand'),
      bottom: FpButton('Continuer', red: true, onPressed: _continue),
      children: [
        FpFlowLabel('Type de paiement'),
        FpFlowTypeList<_PayType>(
          options: [
            FpFlowType(_PayType.flashpay, FpNode.wallet, FpNode.merchant, 'Wallet vers marchand FlashPay'),
            FpFlowType(_PayType.mobileToMerchant, FpNode.mobile, FpNode.merchant, 'Mobile money vers marchand'),
            FpFlowType(_PayType.cardToMerchant, FpNode.card, FpNode.merchant, 'Carte vers marchand'),
            FpFlowType(_PayType.mobileMoney, FpNode.wallet, FpNode.mobile, 'Marchand mobile money'),
          ],
          selected: _type,
          onSelect: (v) => setState(() => _type = v),
        ),
        FpFlowLabel('Désigner le marchand'),
        FpMethodRow<_Designate>(
          methods: [
            FpPickMethod(_Designate.scanner, Icons.center_focus_weak_rounded, 'Scanner', tone: FpTone.blue),
            FpPickMethod(_Designate.nfc, Icons.nfc_rounded, 'NFC'),
            FpPickMethod(_Designate.code, Icons.keyboard_alt_outlined, 'Code marchand', tone: FpTone.blue),
          ],
          selected: _how,
          onSelect: (v) => setState(() => _how = v),
        ),
        SizedBox(height: 16),
        Center(
          child: TextButton.icon(
            onPressed: () => _push(context, PayCodeScreen(purpose: PayCodePurpose.pay)),
            icon: Icon(Icons.qr_code_2_rounded),
            label: Text(tr('Ou montrer mon code de paiement au marchand')),
          ),
        ),
      ],
    );
  }
}
