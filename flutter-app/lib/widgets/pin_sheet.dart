import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Confirmation d'opération par code PIN en bottom-sheet (§4.6.2 : garder un
/// parcours rapide sans changer de page). Retourne le PIN saisi ou null.
class PinSheet extends StatefulWidget {
  final String title;
  final String? subtitle;
  final String? error;
  final bool confirmMode; // définition d'un nouveau PIN (saisie + confirmation)

  PinSheet({super.key, this.title = 'Confirmez avec votre code PIN', this.subtitle, this.error, this.confirmMode = false});

  static Future<String?> ask(BuildContext context, {String? title, String? subtitle, String? error, bool confirmMode = false}) {
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => PinSheet(
        title: title ?? (confirmMode ? 'Créez votre code PIN' : 'Confirmez avec votre code PIN'),
        subtitle: subtitle,
        error: error,
        confirmMode: confirmMode,
      ),
    );
  }

  @override
  State<PinSheet> createState() => _PinSheetState();
}

class _PinSheetState extends State<PinSheet> {
  final _pin = TextEditingController();
  final _confirm = TextEditingController();
  String? _error;

  @override
  void initState() {
    super.initState();
    _error = widget.error;
  }

  void _submit() {
    final pin = _pin.text.trim();
    if (!RegExp(r'^\d{4,6}$').hasMatch(pin)) {
      setState(() => _error = 'Le PIN contient 4 à 6 chiffres.');
      return;
    }
    if (widget.confirmMode && pin != _confirm.text.trim()) {
      setState(() => _error = 'Les deux codes ne correspondent pas.');
      return;
    }
    Navigator.pop(context, pin);
  }

  InputDecoration _deco(String label) => InputDecoration(
        labelText: label,
        counterText: '',
        prefixIcon: Icon(Icons.lock_outline),
        fillColor: FpColors.background,
      );

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(24, 16, 24, 24 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: Colors.black12, borderRadius: BorderRadius.circular(4)))),
          SizedBox(height: 18),
          Text(tr(widget.title), textAlign: TextAlign.center, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          if (widget.subtitle != null) ...[
            SizedBox(height: 6),
            Text(widget.subtitle!, textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
          ],
          SizedBox(height: 18),
          TextField(
            controller: _pin,
            autofocus: true,
            obscureText: true,
            maxLength: 6,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 24, letterSpacing: 12, fontWeight: FontWeight.w700),
            decoration: _deco(widget.confirmMode ? 'Nouveau PIN' : 'PIN'),
            onSubmitted: (_) => widget.confirmMode ? null : _submit(),
          ),
          if (widget.confirmMode) ...[
            SizedBox(height: 10),
            TextField(
              controller: _confirm,
              obscureText: true,
              maxLength: 6,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 24, letterSpacing: 12, fontWeight: FontWeight.w700),
              decoration: _deco('Confirmez le PIN'),
              onSubmitted: (_) => _submit(),
            ),
          ],
          if (_error != null) ...[
            SizedBox(height: 8),
            Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: FpColors.danger)),
          ],
          SizedBox(height: 16),
          ElevatedButton(onPressed: _submit, child: Text(tr('Valider'))),
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        ],
      ),
    );
  }
}
