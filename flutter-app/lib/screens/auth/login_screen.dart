import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/user.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_country.dart';
import '../../widgets/fp_logo.dart';
import '../../widgets/fp_ui.dart';
import '../home_router.dart';
import 'account_recovery_screen.dart';
import 'register_screen.dart';
import '../../l10n/l10n.dart';
import '../../l10n/language_picker.dart';

/// Longueur du code secret de connexion (maquettes : 4 chiffres).
const int kFpSecretLength = 4;

/// Normalise un numéro saisi « 06 123 45 67 » en « +242061234567 ».
/// Un identifiant agent (AG…) est renvoyé tel quel.
String fpLoginIdentifier(String raw, {String dial = '242'}) {
  final t = raw.trim();
  if (RegExp(r'^[Aa][Gg][-\s]?\d+$').hasMatch(t)) return t.toUpperCase().replaceAll(RegExp(r'[-\s]'), '');
  return fpPhoneWithDial(t, dial);
}

/// Connexion — une interface par rôle (client, marchand, agent, caissier) :
/// numéro mobile (ou identifiant agent) + code secret à 4 chiffres.
class LoginScreen extends StatefulWidget {
  final FpProfile profile;
  const LoginScreen({super.key, required this.profile});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _idCtrl = FpPhoneController();
  final _codeCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _usePassword = false; // comptes créés avant le code secret à 4 chiffres
  bool _loading = false;
  String? _error;

  FpProfile get _p => widget.profile;

  String get _title => switch (_p) {
        FpProfile.client => 'Connexion client',
        FpProfile.merchant => 'Connexion marchand',
        FpProfile.agent => 'Connexion agent',
        FpProfile.cashier => 'Connexion caissier',
      };

  String get _subtitle => switch (_p) {
        FpProfile.client => 'Entrez votre numéro et votre code secret',
        FpProfile.merchant => 'Accédez à votre boutique et à vos encaissements',
        FpProfile.agent => 'Accédez à votre espace de dépôts et retraits',
        FpProfile.cashier => 'Encaissez pour le compte de votre marchand',
      };

  /// Comptes de démonstration (DemoAccountsSeeder, code 2580) — mode debug uniquement.
  String get _demoId => switch (_p) {
        FpProfile.client => '06 123 45 67',
        FpProfile.merchant => '06 234 56 78',
        FpProfile.agent => '06 345 67 89',
        FpProfile.cashier => '06 567 89 01',
      };

  void _fillDemo() {
    setState(() {
      _usePassword = false;
      _idCtrl.country = kFpDialCountries.first;
      _idCtrl.text = _demoId;
      _codeCtrl.text = '2580';
      _error = null;
    });
  }

  String get _secret => _usePassword ? _passwordCtrl.text : _codeCtrl.text;

  Future<String?> _askOtp(OtpRequiredException e) => fpAskOtp(context, title: tr('Nouvel appareil'), message: e.message, debugCode: e.debugCode);

  Future<void> _submit() async {
    if (_idCtrl.text.trim().isEmpty) {
      setState(() => _error = _p == FpProfile.agent ? 'Saisissez votre identifiant agent ou votre numéro.' : 'Saisissez votre numéro mobile.');
      return;
    }
    if (!_usePassword && _codeCtrl.text.length < kFpSecretLength) {
      setState(() => _error = 'Saisissez votre code secret à $kFpSecretLength chiffres.');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    final id = fpLoginIdentifier(_idCtrl.text, dial: _idCtrl.country.dial);
    try {
      final session = context.read<SessionProvider>();
      try {
        await session.login(phone: id, password: _secret, profile: _p);
      } on OtpRequiredException catch (e) {
        final code = await _askOtp(e);
        if (code == null || code.isEmpty) return;
        await session.login(phone: id, password: _secret, profile: _p, otp: code);
      }
      if (!mounted) return;
      Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => homeFor(_p)), (route) => false);
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Widget get _header {
    final pro = _p == FpProfile.agent || _p == FpProfile.cashier;
    if (!pro) return FpLogo(size: 76);
    return FpPastille(_p == FpProfile.agent ? Icons.work_outline_rounded : Icons.point_of_sale_outlined,
        tone: _p == FpProfile.agent ? FpTone.blue : FpTone.red, size: 84);
  }

  @override
  Widget build(BuildContext context) {
    final canRegister = _p == FpProfile.client || _p == FpProfile.merchant;
    return Scaffold(
      appBar: AppBar(leading: const BackButton(), actions: const [FpLanguageButton()]),
      body: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: FpAuthCard(
            children: [
              Center(child: _header),
              SizedBox(height: 18),
              Text(_title, textAlign: TextAlign.center, style: TextStyle(fontSize: 27, fontWeight: FontWeight.w500, color: FpColors.ink)),
              SizedBox(height: 6),
              Text(_subtitle, textAlign: TextAlign.center, style: TextStyle(fontSize: 15, color: FpColors.muted)),
              SizedBox(height: 30),
              if (_p == FpProfile.agent)
                FpLineField(label: tr('Identifiant agent ou numéro mobile'), controller: _idCtrl, hint: 'Ex. AG-00214 ou 06 123 45 67', capitalization: TextCapitalization.characters)
              else
                FpPhoneLineField(controller: _idCtrl),
              Text(_usePassword ? 'Mot de passe' : 'Code secret', style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
              SizedBox(height: 12),
              if (_usePassword)
                TextField(
                  controller: _passwordCtrl,
                  obscureText: true,
                  onSubmitted: (_) => _loading ? null : _submit(),
                  decoration: InputDecoration(prefixIcon: Icon(Icons.lock_outline_rounded)),
                )
              else
                FpCodeBoxes(controller: _codeCtrl, length: kFpSecretLength, onCompleted: (_) => FocusScope.of(context).unfocus()),
              Row(children: [
                TextButton(
                  style: TextButton.styleFrom(foregroundColor: FpColors.muted, padding: EdgeInsets.zero),
                  onPressed: () => setState(() => _usePassword = !_usePassword),
                  child: Text(_usePassword ? 'Utiliser mon code secret' : 'Utiliser un mot de passe', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
                ),
                Spacer(),
                TextButton(
                  style: TextButton.styleFrom(padding: EdgeInsets.zero),
                  onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => AccountRecoveryScreen())),
                  child: Text(tr('Code oublié ?'), style: TextStyle(fontSize: 15)),
                ),
              ]),
              SizedBox(height: 28),
              if (_error != null) ...[FpErrorBox(_error!), SizedBox(height: 14)],
              FpButton('Se connecter', onPressed: _submit, loading: _loading),
              if (kDebugMode)
                Center(
                  child: TextButton.icon(
                    style: TextButton.styleFrom(foregroundColor: FpColors.navy),
                    onPressed: _fillDemo,
                    icon: Icon(Icons.auto_fix_high_rounded, size: 18),
                    label: Text(tr('Remplir le compte démo (code 2580)')),
                  ),
                ),
              SizedBox(height: 18),
              if (canRegister)
                Wrap(alignment: WrapAlignment.center, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Text(_p == FpProfile.merchant ? 'Pas encore de boutique ? ' : 'Nouveau sur FlashPay ? ',
                      style: TextStyle(fontSize: 15, color: FpColors.ink)),
                  GestureDetector(
                    onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => RegisterScreen(profile: _p))),
                    child: Text(tr('Créer un compte'), style: TextStyle(fontSize: 15, color: FpColors.red, fontWeight: FontWeight.w500)),
                  ),
                ])
              else
                Text(
                  _p == FpProfile.agent
                      ? 'Les comptes agents sont créés par FlashPay.\nContactez le support pour toute demande.'
                      : 'Les accès caissiers sont créés par votre marchand.\nAdressez-vous à lui pour toute demande.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 13.5, color: FpColors.muted, height: 1.4),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
