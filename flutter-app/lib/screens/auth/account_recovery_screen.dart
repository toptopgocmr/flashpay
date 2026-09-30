import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../l10n/l10n.dart';

/// Récupération de compte (§15) : PIN oublié (OTP + mot de passe + pièce)
/// et déclaration de perte / vol du téléphone ou de la SIM (blocage immédiat).
class AccountRecoveryScreen extends StatefulWidget {
  /// Numéro pré-rempli (ex. depuis l'écran Sécurité, utilisateur connecté).
  final String? initialPhone;
  const AccountRecoveryScreen({super.key, this.initialPhone});

  @override
  State<AccountRecoveryScreen> createState() => _AccountRecoveryScreenState();
}

class _AccountRecoveryScreenState extends State<AccountRecoveryScreen> {
  final _auth = AuthService();
  final _phone = FpPhoneController();
  final _password = TextEditingController();
  final _otp = TextEditingController();
  final _pin = TextEditingController();
  final _pin2 = TextEditingController();

  @override
  void initState() {
    super.initState();
    if (widget.initialPhone != null) _phone.setInternational(widget.initialPhone!);
  }
  bool _otpSent = false;
  String? _debugCode; // code affiché dans l'app tant que les SMS ne sont pas branchés
  bool _loading = false;
  String? _info;
  String? _error;

  Future<void> _run(Future<void> Function() fn) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await fn();
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('Récupération du compte')),
          bottom: TabBar(
            tabs: [Tab(text: tr('PIN oublié')), Tab(text: tr('Téléphone perdu'))],
          ),
        ),
        body: TabBarView(children: [_pinReset(), _lost()]),
      ),
    );
  }

  Widget _messages() => Column(children: [
        if (_info != null) Padding(padding: EdgeInsets.only(top: 12), child: Text(_info!, style: TextStyle(color: FpColors.success))),
        if (_error != null) Padding(padding: EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
      ]);

  /// PIN oublié — 2 étapes : 1) numéro -> code  2) code + nouveau PIN.
  Widget _pinReset() => ListView(padding: EdgeInsets.all(24), children: [
        Text(
          _otpSent ? 'Saisissez le code reçu et choisissez votre nouveau code PIN.' : 'Entrez votre numéro : nous vous envoyons un code pour choisir un nouveau code PIN.',
          style: TextStyle(fontSize: 15),
        ),
        SizedBox(height: 16),
        if (!_otpSent)
          FpPhoneField(label: tr('Numéro de téléphone'), controller: _phone)
        else ...[
          Row(children: [
            Icon(Icons.phone_android, size: 18, color: FpColors.muted),
            SizedBox(width: 6),
            Expanded(child: Text(_phone.international, style: TextStyle(fontWeight: FontWeight.w600))),
            TextButton(onPressed: _loading ? null : () => setState(() { _otpSent = false; _debugCode = null; _otp.clear(); _error = null; }), child: Text(tr('Modifier'))),
          ]),
          if (_debugCode != null) ...[
            SizedBox(height: 8),
            FpOtpCodeCard(code: _debugCode!),
          ],
          SizedBox(height: 12),
          TextField(controller: _otp, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(labelText: tr('Code reçu'), prefixIcon: Icon(Icons.sms_outlined), counterText: '')),
          SizedBox(height: 12),
          TextField(controller: _pin, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(labelText: tr('Nouveau code PIN (4 à 6 chiffres)'), prefixIcon: Icon(Icons.password), counterText: '')),
          SizedBox(height: 12),
          TextField(controller: _pin2, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(labelText: tr('Confirmez le code PIN'), prefixIcon: Icon(Icons.password), counterText: '')),
        ],
        _messages(),
        SizedBox(height: 20),
        ElevatedButton(
          onPressed: _loading
              ? null
              : () => _run(() async {
                    if (!_otpSent) {
                      if (_phone.digitCount < 8) {
                        setState(() => _error = 'Entrez votre numéro de téléphone.');
                        return;
                      }
                      final debug = await _auth.requestOtp(_phone.international, 'pin_reset');
                      setState(() {
                        _otpSent = true;
                        _debugCode = debug;
                        if (debug != null) _otp.text = debug;
                        _info = debug != null ? null : 'Code envoyé par SMS au ${_phone.international}.';
                      });
                      return;
                    }
                    final pin = _pin.text.trim();
                    if (!RegExp(r'^\d{4,6}$').hasMatch(pin)) {
                      setState(() => _error = 'Le code PIN doit contenir 4 à 6 chiffres.');
                      return;
                    }
                    if (pin != _pin2.text.trim()) {
                      setState(() => _error = 'Les deux codes PIN ne sont pas identiques.');
                      return;
                    }
                    await _auth.resetPin(phone: _phone.international, otp: _otp.text.trim(), newPin: pin);
                    if (!mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(tr('Nouveau code PIN enregistré.'))));
                    Navigator.pop(context);
                  }),
          child: Text(_otpSent ? 'Valider mon nouveau PIN' : 'Recevoir le code'),
        ),
        if (_otpSent)
          TextButton(
            onPressed: _loading
                ? null
                : () => _run(() async {
                      final debug = await _auth.requestOtp(_phone.international, 'pin_reset');
                      setState(() {
                        _debugCode = debug;
                        if (debug != null) _otp.text = debug;
                        _info = 'Nouveau code envoyé.';
                      });
                    }),
            child: Text(tr('Renvoyer le code')),
          ),
      ]);

  Widget _lost() => ListView(padding: EdgeInsets.all(24), children: [
        Icon(Icons.phonelink_erase, size: 56, color: FpColors.danger),
        SizedBox(height: 12),
        Text(tr('Votre téléphone ou votre carte SIM a été perdu(e) ou volé(e) ? Bloquez immédiatement votre compte : tous vos appareils sont déconnectés et aucune opération n\'est possible jusqu\'à la vérification de votre identité par le support FlashPay.'),
            textAlign: TextAlign.center),
        SizedBox(height: 20),
        FpPhoneField(label: tr('Numéro du compte'), controller: _phone),
        SizedBox(height: 12),
        TextField(controller: _password, obscureText: true, decoration: InputDecoration(labelText: tr('Mot de passe'), prefixIcon: Icon(Icons.lock))),
        _messages(),
        SizedBox(height: 20),
        ElevatedButton(
          style: ElevatedButton.styleFrom(backgroundColor: FpColors.danger, foregroundColor: Colors.white),
          onPressed: _loading
              ? null
              : () => _run(() async {
                    if (_phone.digitCount < 8 || _password.text.isEmpty) {
                      setState(() => _error = 'Entrez le numéro du compte et son mot de passe.');
                      return;
                    }
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: Text(tr('Bloquer le compte ?')),
                        content: Text('Le compte ${_phone.international} sera bloqué immédiatement : plus aucune connexion ni opération. '
                            'Pour le débloquer, il faudra vous présenter en agence FlashPay avec votre pièce d\'identité.'),
                        actions: [
                          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
                          FilledButton(style: FilledButton.styleFrom(backgroundColor: FpColors.danger), onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Bloquer'))),
                        ],
                      ),
                    );
                    if (ok != true) return;
                    final msg = await _auth.reportLost(phone: _phone.international, password: _password.text);
                    _password.clear();
                    if (!mounted) return;
                    await showDialog<void>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        icon: Icon(Icons.lock, color: FpColors.danger, size: 40),
                        title: Text(tr('Compte bloqué')),
                        content: Text(msg),
                        actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Compris')))],
                      ),
                    );
                    setState(() => _info = msg);
                  }),
          child: Text(tr('Bloquer mon compte')),
        ),
      ]);
}
