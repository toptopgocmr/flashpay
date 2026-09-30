import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../config/theme.dart';
import '../../widgets/fp_design.dart';
import '../../l10n/l10n.dart';

/// Traduction instantanée (pour échanger avec un correspondant étranger :
/// client, fournisseur, famille). Utilise le service public MyMemory ;
/// aucune donnée de paiement n'est transmise.
class TranslationScreen extends StatefulWidget {
  const TranslationScreen({super.key});

  @override
  State<TranslationScreen> createState() => _TranslationScreenState();
}

class _TranslationScreenState extends State<TranslationScreen> {
  static const _langs = {
    'fr': 'Français',
    'en': 'Anglais',
    'ln': 'Lingala',
    'sw': 'Swahili',
    'pt': 'Portugais',
    'es': 'Espagnol',
    'ar': 'Arabe',
    'zh': 'Chinois',
    'tr': 'Turc',
  };

  final _input = TextEditingController();
  final _dio = Dio(BaseOptions(connectTimeout: Duration(seconds: 12), receiveTimeout: Duration(seconds: 12)));
  String _from = 'fr';
  String _to = 'en';
  String? _result;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _translate() async {
    final text = _input.text.trim();
    if (text.isEmpty) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = await _dio.get('https://api.mymemory.translated.net/get', queryParameters: {'q': text, 'langpair': '$_from|$_to'});
      final t = res.data is Map ? '${res.data['responseData']?['translatedText'] ?? ''}' : '';
      if (!mounted) return;
      setState(() {
        _result = t.isEmpty ? null : t;
        if (t.isEmpty) _error = 'Aucune traduction trouvée.';
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'Traduction indisponible pour le moment. Vérifiez votre connexion.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _langPicker(String value, ValueChanged<String> onChanged) => Expanded(
        child: Container(
          padding: EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: FpColors.line)),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              value: value,
              isExpanded: true,
              items: _langs.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
              onChanged: (v) {
                if (v != null) onChanged(v);
              },
            ),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Traduction'))),
      body: ListView(padding: EdgeInsets.fromLTRB(18, 4, 18, 24), children: [
        Row(children: [
          _langPicker(_from, (v) => setState(() => _from = v)),
          IconButton(
            tooltip: tr('Inverser'),
            onPressed: () => setState(() {
              final f = _from;
              _from = _to;
              _to = f;
              if (_result != null) {
                _input.text = _result!;
                _result = null;
              }
            }),
            icon: Icon(Icons.swap_horiz_rounded, color: FpColors.red),
          ),
          _langPicker(_to, (v) => setState(() => _to = v)),
        ]),
        SizedBox(height: 14),
        TextField(
          controller: _input,
          minLines: 4,
          maxLines: 8,
          maxLength: 500,
          decoration: InputDecoration(hintText: tr('Saisissez le texte à traduire…')),
        ),
        SizedBox(height: 6),
        FpButton('Traduire', red: true, loading: _busy, onPressed: _translate),
        SizedBox(height: 18),
        if (_error != null) Text(_error!, style: TextStyle(color: FpColors.danger)),
        if (_result != null)
          Container(
            padding: EdgeInsets.all(18),
            decoration: BoxDecoration(color: FpColors.soft, borderRadius: BorderRadius.circular(20)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(_langs[_to] ?? _to, style: TextStyle(color: FpColors.navy, fontWeight: FontWeight.w600)),
              SizedBox(height: 8),
              SelectableText(_result!, style: TextStyle(fontSize: 17, height: 1.4)),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: _result!));
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(tr('Traduction copiée'))));
                  },
                  icon: Icon(Icons.copy_rounded, size: 18),
                  label: Text(tr('Copier')),
                ),
              ),
            ]),
          ),
      ]),
    );
  }
}
