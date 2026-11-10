import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { PaperclipIcon, XIcon } from 'lucide-react'
import { PhotoPickers as Pickers } from '@/components/ui/PhotoPickers'
import { shrink } from '@/lib/photos'
import { Button } from '@/components/ui/Button'
import { apiUrl, ApiError, put, upload } from '@/lib/api'
import { cn } from '@/lib/cn'

export type Proof = { id: number; mime: string }

const proofUrl = (id: number) => apiUrl(`/payment-proofs/${id}`)

function useUploadProof() {
  return useMutation({
    mutationFn: async (file: File) => (await upload<{ data: Proof }>('/payment-proofs', 'file', await shrink(file))).data,
    meta: { toast: false },
  })
}

/** Optional proof on a payment form: take a photo or choose a file; shows a small preview. */
export function ProofField({ value, onChange }: { value: Proof | null; onChange: (p: Proof | null) => void }) {
  const { t } = useTranslation()
  const up = useUploadProof()
  const [preview, setPreview] = useState<string | null>(null)
  const error = up.error instanceof ApiError ? (up.error.field('file') ?? up.error.message) : null

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-sm font-medium">
        {t('proof.label')} <span className="font-normal text-muted">{t('proof.optional')}</span>
      </span>
      {value ? (
        <div className="flex items-center gap-3 rounded-lg bg-sunken p-2">
          {preview && value.mime.startsWith('image/') ? (
            <img src={preview} alt={t('proof.label')} className="size-12 rounded-md object-cover" />
          ) : (
            <PaperclipIcon aria-hidden className="size-5 text-icon" />
          )}
          <span className="flex-1 text-sm">{t('proof.added')}</span>
          <Button
            type="button"
            variant="ghost"
            aria-label={t('proof.remove')}
            onClick={() => {
              onChange(null)
              setPreview(null)
            }}
          >
            <XIcon aria-hidden className="size-4" />
          </Button>
        </div>
      ) : (
        <Pickers
          disabled={up.isPending}
          onFile={(f) =>
            up.mutate(f, {
              onSuccess: (p) => {
                onChange(p)
                setPreview(f.type.startsWith('image/') && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(f) : null)
              },
            })
          }
        />
      )}
      {up.isPending && <span className="text-xs text-muted">{t('proof.uploading')}</span>}
      {error && <span className="text-xs text-danger-text">{error}</span>}
    </div>
  )
}

/** On a payment line: open the proof, or add one later. */
export function ProofLink({ paymentId, proof, canAdd }: { paymentId: number; proof: Proof | null | undefined; canAdd: boolean }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const up = useUploadProof()
  const attach = useMutation({
    mutationFn: (proofId: number) => put(`/payments/${paymentId}/proof`, { proof_id: proofId }),
    onSuccess: () => Promise.all(['reservations', 'invoices', 'desk'].map((k) => qc.invalidateQueries({ queryKey: [k] }))),
    meta: { toast: 'proof.saved' },
  })
  const file = useRef<HTMLInputElement>(null)

  if (proof) {
    // Not "noreferrer": the sign-in cookie only counts when the browser says the request comes from the app.
    return (
      <a href={proofUrl(proof.id)} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs font-medium text-accent-text hover:underline">
        <PaperclipIcon aria-hidden className="size-3.5" />
        {t('proof.view')}
      </a>
    )
  }
  if (!canAdd) return null
  return (
    <>
      <input
        ref={file}
        type="file"
        accept="image/*,application/pdf"
        className="hidden"
        aria-label={t('proof.add')}
        onChange={(e) => {
          const f = e.target.files?.[0]
          e.target.value = ''
          if (f) up.mutate(f, { onSuccess: (p) => attach.mutate(p.id) })
        }}
      />
      <button
        type="button"
        disabled={up.isPending || attach.isPending}
        onClick={() => file.current?.click()}
        className={cn('tap-link inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-text disabled:opacity-60')}
      >
        <PaperclipIcon aria-hidden className="size-3.5" />
        {up.isPending || attach.isPending ? t('proof.uploading') : t('proof.add')}
      </button>
    </>
  )
}
