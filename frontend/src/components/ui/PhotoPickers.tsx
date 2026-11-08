import { useRef, type ChangeEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Camera, FileUp } from 'lucide-react'
import { Button } from '@/components/ui/Button'

/** Two hidden file inputs: the camera (on phones and tablets) and "choose a file". */
export function PhotoPickers({ onFile, disabled }: { onFile: (f: File) => void; disabled?: boolean }) {
  const { t } = useTranslation()
  const camera = useRef<HTMLInputElement>(null)
  const file = useRef<HTMLInputElement>(null)
  const pick = (e: ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (f) onFile(f)
  }
  return (
    <div className="flex flex-wrap gap-2">
      <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} aria-label={t('proof.takePhoto')} />
      <input ref={file} type="file" accept="image/*,application/pdf" className="hidden" onChange={pick} aria-label={t('proof.chooseFile')} />
      <Button type="button" variant="secondary" disabled={disabled} onClick={() => camera.current?.click()}>
        <Camera aria-hidden />
        {t('proof.takePhoto')}
      </Button>
      <Button type="button" variant="secondary" disabled={disabled} onClick={() => file.current?.click()}>
        <FileUp aria-hidden />
        {t('proof.chooseFile')}
      </Button>
    </div>
  )
}

